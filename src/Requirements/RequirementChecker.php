<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Requirements;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\CheckStatus;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\McpServer;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;

final class RequirementChecker
{
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly AuditConfig $config,
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

        $checks = $this->legacyKeys();
        $tests = $selection->needs(PreflightStep::Tests);
        $browser = $selection->needs(PreflightStep::Browser) && ! $selection->skips(OptionalStep::Browser);

        if ($agents) {
            array_push($checks, ...$this->agent());
        }

        if ($selection->needs(PreflightStep::Composer)
            && $this->project->findExecutable('composer') === null
            && ! is_file($this->project->path('composer.phar'))) {
            $checks[] = Check::failed($this->messages->get('requirements.composer_missing'));
        }

        $checks[] = $this->outputDirectory();

        if ($agents) {
            $checks[] = McpServer::forProject($this->project) !== null
                ? Check::ok($this->messages->get('requirements.boost', ['count' => count($this->config->strings('agent.boost_tools'))]))
                : Check::info($this->messages->get('requirements.boost_missing'));
        }

        if ($selection->needs(PreflightStep::Phpstan) && ! $selection->skips(OptionalStep::Phpstan)) {
            $config = $this->project->phpstanConfigPath();
            $checks[] = $config !== null && $this->project->hasVendorBinary('phpstan')
                ? Check::ok($this->messages->get('requirements.phpstan', ['config' => basename($config)]))
                : Check::warning($this->messages->get('requirements.phpstan_no_config'));
        }

        if ($tests) {
            if ($this->config->bool('preflight.tests.coverage') && $selection->wantsCoverage()) {
                $driver = $this->project->coverageDriver();
                $checks[] = $driver !== null
                    ? Check::ok($this->messages->get('requirements.coverage', ['driver' => $driver]))
                    : Check::warning($this->messages->get('requirements.coverage_missing'));
            }

            if ($this->config->bool('preflight.tests.parallel') && ! $this->project->canRunParallel()) {
                $checks[] = Check::info($this->messages->get('requirements.paratest_missing'));
            }
        }

        if ($tests || $browser) {
            $database = $this->guard->check();
            $checks[] = $database->safe || $this->config->bool('preflight.allow_shared_database')
                ? Check::ok($this->messages->get('requirements.database_safe', ['test' => $database->testDatabase]))
                : Check::warning($this->messages->get('requirements.database_shared', ['app' => $database->appDatabase]));
        }

        if ($browser) {
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

    /** @return list<Check> */
    private function agent(): array
    {
        $driver = $this->driver;
        $replace = ['agent' => $driver->displayName(), 'binary' => $driver->binary(), 'model' => $driver->model() ?? $this->messages->get('requirements.agent_default_model')];

        if (! $driver->isAvailable()) {
            return [Check::failed($this->messages->get('requirements.agent_missing', $replace))];
        }

        return [Check::ok($this->messages->get('requirements.agent', $replace))];
    }

    private function outputDirectory(): Check
    {
        $path = $this->config->string('output_path');
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
            $this->config->string('preflight.browser.testsuite'),
            $this->config->string('preflight.browser.path'),
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

    /**
     * Keys a published config still sets although they are no longer read.
     *
     * @return list<Check>
     */
    private function legacyKeys(): array
    {
        $checks = [];

        foreach ($this->config->legacyKeys() as $old => $new) {
            $checks[] = Check::warning($new === null
                ? $this->messages->get('requirements.legacy_key_removed', ['old' => $old])
                : $this->messages->get('requirements.legacy_key', ['old' => $old, 'new' => $new]));
        }

        return $checks;
    }
}
