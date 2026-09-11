<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Arr;

final class PromptContextBuilder
{
    /** @param array<string, mixed> $config */
    public function build(PreflightSummary $summary, AuditRun $run, ProjectInspector $project, array $config, string $locale, bool $boostAvailable): PromptContext
    {
        $frontend = (array) $summary->get('stack.frontend', []);

        return new PromptContext(
            projectName: (string) ($summary->get('app.name') ?: config('app.name', 'Laravel')),
            laravelVersion: (string) ($summary->get('app.laravel') ?: 'unknown'),
            phpVersion: (string) ($summary->get('app.php') ?: PHP_VERSION),
            testFramework: (string) ($summary->get('stack.test_framework') ?: 'unknown'),
            frontendStack: $frontend === [] ? 'none detected' : implode(', ', $frontend),
            phpstanLevel: (string) ($summary->get('steps.phpstan.level') ?: 'unknown'),
            browserTests: $this->browserTests($summary),
            runId: $run->id,
            outputDir: $project->relative($run->dir()),
            preflightDir: $project->relative($run->preflightDir()),
            reportLanguage: $locale,
            excludePaths: array_values((array) Arr::get($config, 'exclude_paths', [])),
            customChecks: array_map(fn ($rules): array => array_values((array) $rules), (array) Arr::get($config, 'custom_checks', [])),
            maxFindings: (int) Arr::get($config, 'max_findings', 25),
            boostAvailable: $boostAvailable,
        );
    }

    private function browserTests(PreflightSummary $summary): string
    {
        $browser = $summary->step(PreflightStep::Browser);

        return match ($summary->status(PreflightStep::Browser)) {
            StepStatus::Ok => sprintf(
                'ran (%d tests, %d failed%s)',
                $browser['total'] ?? 0,
                ($browser['failed'] ?? 0) + ($browser['errors'] ?? 0),
                ($browser['environment_error'] ?? false) ? ', environment error — results unreliable' : '',
            ),
            StepStatus::Skipped => 'not run ('.($browser['reason'] ?? 'skipped').')',
            StepStatus::Error => 'error ('.mb_strimwidth((string) ($browser['error'] ?? ''), 0, 120, '…').')',
            null => 'not part of this run',
        };
    }
}
