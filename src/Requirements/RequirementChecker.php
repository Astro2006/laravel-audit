<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Requirements;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\CheckStatus;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Arr;

/**
 * What `php artisan audit` checks before it starts. It never installs
 * anything; missing parts point to `php artisan audit:install`.
 */
final class RequirementChecker
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly array $config,
        private readonly DatabaseGuard $guard,
        private readonly AgentDriver $driver,
        private readonly Messages $messages,
    ) {}

    /** @return list<Check> */
    public function check(RunSelection $selection, bool $agents, bool $production): array
    {
        if ($production) {
            return [Check::failed($this->messages->get('requirements.production'))];
        }

        $checks = [];
        $tests = $selection->includes(AuditType::Tests);
        $browserRelevant = ($tests || $selection->includes(AuditType::Frontend)) && ! $selection->skips(OptionalStep::Browser);

        if ($agents) {
            $checks[] = $this->driver->isAvailable()
                ? Check::ok($this->messages->get('requirements.claude'))
                : Check::failed($this->messages->get('requirements.claude_missing', ['binary' => (string) $this->config('agent.binary', 'claude')]));
        }

        if ($selection->includes(AuditType::Dependencies)
            && $this->project->findExecutable('composer') === null
            && ! is_file($this->project->path('composer.phar'))) {
            $checks[] = Check::failed($this->messages->get('requirements.composer_missing'));
        }

        $checks[] = $this->outputDirectory();

        if ($agents) {
            $checks[] = $this->project->boostMcpServer() !== null
                ? Check::ok($this->messages->get('requirements.boost', ['count' => count((array) $this->config('agent.boost_tools', []))]))
                : Check::info($this->messages->get('requirements.boost_missing'));
        }

        if (($tests || $selection->includes(AuditType::Backend)) && ! $selection->skips(OptionalStep::Phpstan) && $this->config('preflight.phpstan.enabled', true)) {
            $config = $this->project->phpstanConfigPath();
            $checks[] = $config !== null && $this->project->hasVendorBinary('phpstan')
                ? Check::ok($this->messages->get('requirements.phpstan', ['config' => basename($config)]))
                : Check::warning($this->messages->get('requirements.phpstan_no_config'));
        }

        if ($tests) {
            if ($this->config('preflight.tests.coverage', true) && ! $selection->skips(OptionalStep::Coverage)) {
                $driver = $this->project->coverageDriver();
                $checks[] = $driver !== null
                    ? Check::ok($this->messages->get('requirements.coverage', ['driver' => $driver]))
                    : Check::warning($this->messages->get('requirements.coverage_missing'));
            }

            if ($this->config('preflight.tests.parallel', true) && ! $this->project->canRunParallel()) {
                $checks[] = Check::info($this->messages->get('requirements.paratest_missing'));
            }
        }

        if ($tests || $browserRelevant) {
            $database = $this->guard->check();
            $checks[] = $database->safe || $this->config('preflight.allow_shared_database', false)
                ? Check::ok($this->messages->get('requirements.database_safe', ['test' => $database->testDatabase]))
                : Check::warning($this->messages->get('requirements.database_shared', ['app' => $database->appDatabase]));
        }

        if ($browserRelevant && $this->config('preflight.browser.enabled', true)) {
            $checks[] = $this->browser();
        }

        return $checks;
    }

    /** @param list<Check> $checks */
    public static function hasFailures(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->status === CheckStatus::Failed) {
                return true;
            }
        }

        return false;
    }

    private function outputDirectory(): Check
    {
        $path = (string) $this->config('output_path', $this->project->path('storage/audit'));
        $existing = $path;

        while (! is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $relative = $this->project->relative($path);

        return is_writable($existing)
            ? Check::ok($this->messages->get('requirements.output', ['path' => $relative]))
            : Check::failed($this->messages->get('requirements.output_not_writable', ['path' => $relative]));
    }

    private function browser(): Check
    {
        $location = $this->project->browserTests(
            (string) $this->config('preflight.browser.testsuite', 'Browser'),
            (string) $this->config('preflight.browser.path', 'tests/Browser'),
        );

        if ($location === null) {
            return Check::info($this->messages->get('requirements.browser_none'));
        }

        if (! $this->project->hasBrowserPlugin() || ! $this->project->hasNodeModule('playwright')) {
            return Check::warning($this->messages->get('requirements.browser_plugin_missing'));
        }

        return Check::ok($this->messages->get('requirements.browser', [
            'location' => $location->isSuite() ? 'Suite "'.$location->value.'"' : $location->value,
        ]));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }
}
