<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Astro2006\LaravelAudit\Agents\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\DeniedFiles;
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
use Astro2006\LaravelAudit\Prompts\PromptRenderer;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Illuminate\Support\Arr;

/**
 * Builds the harness pieces from config/audit.php.
 */
class HarnessFactory
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    /** @return array<string, mixed> */
    public function allConfig(): array
    {
        return $this->config;
    }

    public function project(): ProjectInspector
    {
        return new ProjectInspector((string) ($this->config('base_path') ?? base_path()));
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

    public function driver(ProjectInspector $project): AgentDriver
    {
        $budget = $this->config('agent.max_budget_usd');

        return new ClaudeCodeDriver(
            binary: (string) $this->config('agent.binary', 'claude'),
            model: $this->config('agent.model') ?: null,
            effort: $this->config('agent.effort') ?: null,
            maxBudgetUsd: $budget === null || $budget === '' ? null : (float) $budget,
            boostTools: array_values((array) $this->config('agent.boost_tools', [])),
            deniedFiles: DeniedFiles::resolve(
                $project->basePath,
                array_values((array) $this->config('agent.deny_read', [])),
                array_values((array) $this->config('agent.allow_read', [])),
            ),
        );
    }

    public function promptRenderer(): PromptRenderer
    {
        return new PromptRenderer(
            dirname(__DIR__, 2).'/resources/prompts',
            function_exists('resource_path') ? resource_path('prompts/vendor/laravel-audit') : null,
        );
    }

    public function validator(ProjectInspector $project): ReportValidator
    {
        return new ReportValidator(new ReportLinter($project->basePath, (int) $this->config('max_findings', 25)));
    }
}
