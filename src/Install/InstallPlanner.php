<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;

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
        private readonly ?AgentDriver $driver = null,
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

        // The audit starts Boost's MCP server itself (php artisan boost:mcp),
        // so an installed Boost needs no further setup.
        if (! $project->isInstalled('laravel/boost')) {
            $plan->packages[] = new PlannedPackage('laravel/boost', 'composer');
            $plan->setup[] = 'boost';
        }

        if ($this->needs(PreflightStep::Tests) && ! $project->canRunParallel() && $project->isInstalled('phpunit/phpunit')) {
            $plan->packages[] = new PlannedPackage('brianium/paratest', 'composer');
        }

        if ($this->needs(PreflightStep::Browser)) {
            $this->planBrowser($plan);
        }

        if ($project->phpstanConfigPath() === null && $this->needs(PreflightStep::Phpstan)) {
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
            $plan->notes[] = new InstallNote('install.phpunit_only');
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
            Read::string($this->config, 'preflight.browser.testsuite', 'Browser'),
            Read::string($this->config, 'preflight.browser.path', 'tests/Browser'),
        );

        if ($location === null) {
            $plan->notes[] = new InstallNote('install.no_browser_tests');
        }
    }

    private function planHints(InstallPlan $plan): void
    {
        $project = $this->project;

        if ($this->needs(PreflightStep::Tests) && $project->coverageDriver() === null) {
            $plan->manual[] = 'requirements.coverage_missing';
        }

        if ($this->driver !== null && ! $this->driver->isAvailable()) {
            $plan->manual[] = 'requirements.agent_missing';
        }

        if ($plan->npm() !== [] && $project->findExecutable('npm') === null && $project->findExecutable($project->packageManager()->value) === null) {
            $plan->manual[] = 'node';
        }

        if ($this->guard !== null && $this->needs(PreflightStep::Tests)) {
            $database = $this->guard->check();

            if (! $database->safe) {
                $plan->notes[] = new InstallNote('requirements.database_shared', ['app' => $database->appDatabase]);
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

    /** Whether an enabled audit lists the step in its config. */
    private function needs(PreflightStep $step): bool
    {
        try {
            return RunSelection::resolve(Read::array($this->config, 'audits'), [], [])->needs($step);
        } catch (InvalidSelection) {
            return false;
        }
    }
}
