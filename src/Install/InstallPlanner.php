<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Arr;

/**
 * Collects what is missing. Larastan and Pint come with this package; the
 * plan only contains optional parts: Boost, paratest, the Pest browser
 * plugin and Playwright.
 */
final class InstallPlanner
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly array $config,
        private readonly ?DatabaseGuard $guard = null,
        private readonly ?string $claudeBinary = null,
    ) {}

    public function plan(): InstallPlan
    {
        $plan = new InstallPlan;
        $project = $this->project;

        foreach (['larastan/larastan', 'laravel/pint', 'pestphp/pest', 'laravel/boost', 'pestphp/pest-plugin-browser', 'brianium/paratest'] as $package) {
            if ($project->isInstalled($package)) {
                $plan->present[] = $package;
            }
        }

        if ($project->hasNodeModule('playwright')) {
            $plan->present[] = 'playwright';
        }

        if (! $project->isInstalled('laravel/boost')) {
            $plan->packages[] = new PlannedPackage('laravel/boost', 'composer');
        }

        if (! $project->isInstalled('laravel/boost') || $project->boostMcpServer() === null) {
            $plan->setup[] = 'boost';
        }

        if ($this->active(AuditType::Tests) && ! $project->canRunParallel() && $project->isInstalled('phpunit/phpunit')) {
            $plan->packages[] = new PlannedPackage('brianium/paratest', 'composer');
        }

        if (($this->active(AuditType::Tests) || $this->active(AuditType::Frontend)) && Arr::get($this->config, 'preflight.browser.enabled', true)) {
            $this->planBrowser($plan);
        }

        if ($project->phpstanConfigPath() === null && ($this->active(AuditType::Tests) || $this->active(AuditType::Backend))) {
            $plan->setup[] = 'phpstan-stub';
        }

        $this->planHints($plan);

        return $plan;
    }

    private function planBrowser(InstallPlan $plan): void
    {
        $project = $this->project;
        $pestMajor = $project->majorVersion('pestphp/pest');
        $pestPlanned = false;

        if (! $project->usesPest()) {
            $plan->packages[] = new PlannedPackage('pestphp/pest', 'composer', upgradeReason: 'PHPUnit → Pest');
            $plan->notes[] = ['key' => 'install.phpunit_only'];
            $pestPlanned = true;
        } elseif ($pestMajor !== null && $pestMajor < 4) {
            $plan->packages[] = new PlannedPackage('pestphp/pest', 'composer', upgradeReason: 'Pest '.$pestMajor.' → Pest 4+');
            $pestPlanned = true;
        }

        if (! $project->hasBrowserPlugin()) {
            $plan->packages[] = new PlannedPackage('pestphp/pest-plugin-browser', 'composer', requires: $pestPlanned ? 'pestphp/pest' : null);
        }

        if (! $project->hasNodeModule('playwright')) {
            $plan->packages[] = new PlannedPackage('playwright', 'npm', requires: $project->hasBrowserPlugin() ? null : 'pestphp/pest-plugin-browser', install: 'playwright@latest');
        }

        if (! $project->hasNodeModule('playwright') || ! $this->playwrightBrowsersInstalled()) {
            $plan->setup[] = 'playwright-browsers';
        }

        $location = $project->browserTests(
            (string) Arr::get($this->config, 'preflight.browser.testsuite', 'Browser'),
            (string) Arr::get($this->config, 'preflight.browser.path', 'tests/Browser'),
        );

        if ($location === null) {
            $plan->notes[] = ['key' => 'install.no_browser_tests'];
        }
    }

    private function planHints(InstallPlan $plan): void
    {
        $project = $this->project;

        if ($this->active(AuditType::Tests) && $project->coverageDriver() === null) {
            $plan->manual[] = 'requirements.coverage_missing';
        }

        if ($this->claudeBinary !== null && ! is_file($this->claudeBinary) && $project->findExecutable($this->claudeBinary) === null) {
            $plan->manual[] = 'requirements.claude_missing';
        }

        if ($plan->npm() !== [] && $project->findExecutable('npm') === null && $project->findExecutable($project->packageManager()->value) === null) {
            $plan->manual[] = 'node';
        }

        if ($this->guard !== null && $this->active(AuditType::Tests)) {
            $database = $this->guard->check();

            if (! $database->safe) {
                $plan->notes[] = ['key' => 'requirements.database_shared', 'replace' => ['app' => $database->appDatabase]];
            }
        }
    }

    /** Playwright keeps its browsers in a cache folder outside the project. */
    private function playwrightBrowsersInstalled(): bool
    {
        $home = (string) (getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '');

        if ($home === '') {
            $user = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
            $home = match (PHP_OS_FAMILY) {
                'Darwin' => $user.'/Library/Caches/ms-playwright',
                'Windows' => (string) getenv('LOCALAPPDATA').'\\ms-playwright',
                default => $user.'/.cache/ms-playwright',
            };
        }

        return (glob($home.'/chromium*') ?: []) !== [];
    }

    private function active(AuditType $audit): bool
    {
        return (bool) Arr::get($this->config, 'audits.'.$audit->value, true);
    }
}
