<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\Steps\AboutStep;
use Astro2006\LaravelAudit\Preflight\Steps\BrowserStep;
use Astro2006\LaravelAudit\Preflight\Steps\ComposerStep;
use Astro2006\LaravelAudit\Preflight\Steps\LinesOfCodeStep;
use Astro2006\LaravelAudit\Preflight\Steps\PhpstanStep;
use Astro2006\LaravelAudit\Preflight\Steps\PintStep;
use Astro2006\LaravelAudit\Preflight\Steps\RoutesStep;
use Astro2006\LaravelAudit\Preflight\Steps\StackStep;
use Astro2006\LaravelAudit\Preflight\Steps\TestsStep;
use Astro2006\LaravelAudit\Preflight\Steps\TranslationsStep;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Snapshots\BrowserSnapshot;
use Astro2006\LaravelAudit\Snapshots\ComposerSnapshot;
use Astro2006\LaravelAudit\Snapshots\CoverageSnapshot;
use Astro2006\LaravelAudit\Snapshots\PhpstanSnapshot;
use Astro2006\LaravelAudit\Snapshots\PintSnapshot;
use Astro2006\LaravelAudit\Snapshots\RoutesSnapshot;
use Astro2006\LaravelAudit\Snapshots\RouteTestsSnapshot;
use Astro2006\LaravelAudit\Snapshots\SnapshotWriter;
use Astro2006\LaravelAudit\Snapshots\StatusSnapshot;
use Astro2006\LaravelAudit\Snapshots\TestsSnapshot;
use Astro2006\LaravelAudit\Snapshots\TranslationsSnapshot;

/**
 * Builds the harness pieces from config/audit.php.
 */
class HarnessFactory
{
    public function __construct(public readonly AuditConfig $config) {}

    public function project(): ProjectInspector
    {
        return new ProjectInspector($this->config->nullableString('base_path') ?? base_path());
    }

    public function databaseGuard(ProjectInspector $project): DatabaseGuard
    {
        return new DatabaseGuard(
            $project,
            (array) config('database.connections', []),
            (string) config('database.default', 'sqlite'),
            $this->childEnvironment($project)->inherited(),
        );
    }

    public function childEnvironment(ProjectInspector $project): ChildEnvironment
    {
        return ChildEnvironment::for($project, app()->environmentFile());
    }

    /** @return list<Step> */
    public function steps(ProjectInspector $project, DatabaseGuard $guard): array
    {
        return [
            new AboutStep,
            new StackStep,
            new LinesOfCodeStep,
            new PintStep,
            new RoutesStep,
            new TranslationsStep((string) config('app.locale', 'en')),
            new ComposerStep,
            new TestsStep($guard),
            new BrowserStep($guard),
            new PhpstanStep,
        ];
    }

    public function snapshotWriter(): SnapshotWriter
    {
        return new SnapshotWriter([
            new StatusSnapshot,
            new TestsSnapshot,
            new BrowserSnapshot,
            new PhpstanSnapshot,
            new CoverageSnapshot,
            new RouteTestsSnapshot,
            new RoutesSnapshot,
            new PintSnapshot,
            new TranslationsSnapshot,
            new ComposerSnapshot,
        ], max(1, $this->config->int('snapshot_rows')));
    }

    /**
     * @param  string|null  $name  driver name or `auto`; null = agent.driver
     * @param  string|null  $model  overrides the configured model
     */
    public function driver(ProjectInspector $project, ?string $name = null, ?string $model = null): AgentDriver
    {
        return (new DriverRegistry($this->config, $project))->resolve($name, $model);
    }

    /** Published prompts (vendor:publish --tag=audit-prompts); files there win over the package's own. */
    public function promptOverridePath(): ?string
    {
        return function_exists('resource_path') ? resource_path('prompts/vendor/laravel-audit') : null;
    }

    public function validator(ProjectInspector $project): ReportValidator
    {
        return new ReportValidator(new ReportLinter($project->basePath));
    }
}
