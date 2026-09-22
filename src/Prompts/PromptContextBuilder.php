<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;

final class PromptContextBuilder
{
    /**
     * @param  array<mixed>  $config
     * @param  array<string, string>  $snapshots  snapshot type => markdown (SnapshotWriter::write())
     */
    public function build(PreflightSummary $summary, AuditRun $run, ProjectInspector $project, array $config, string $locale, bool $boostAvailable, string $toolsNote = '', array $snapshots = []): PromptContext
    {
        $steps = $summary->steps;
        $about = $steps->about();
        $stack = $steps->stack();
        $frontend = $stack === null ? [] : $stack->frontend;

        $customChecks = [];

        foreach (Read::keys($config, 'custom_checks') as $audit) {
            $customChecks = [...$customChecks, $audit => Read::strings($config, 'custom_checks.'.$audit)];
        }

        return new PromptContext(
            projectName: $about?->name ?: (string) config('app.name', 'Laravel'),
            laravelVersion: $about?->laravel ?: 'unknown',
            phpVersion: $about?->php ?: PHP_VERSION,
            testFramework: $stack === null ? 'unknown' : $stack->testFramework,
            frontendStack: $frontend === [] ? 'none detected' : implode(', ', $frontend),
            phpstanLevel: $steps->phpstan()->level ?? 'unknown',
            browserTests: $this->browserTests($summary),
            runId: $run->id,
            outputDir: $project->relative($run->dir()),
            preflightDir: $project->relative($run->preflightDir()),
            reportLanguage: $locale,
            excludePaths: Read::strings($config, 'exclude_paths'),
            customChecks: $customChecks,
            maxFindings: max(1, Read::int($config, 'max_findings_per_task', 5)),
            boostAvailable: $boostAvailable,
            toolsNote: $toolsNote,
            maxFiles: max(1, Read::int($config, 'max_files_per_task', 25)),
            snapshots: $snapshots,
        );
    }

    private function browserTests(PreflightSummary $summary): string
    {
        $result = $summary->steps->get(PreflightStep::Browser);
        $browser = $summary->steps->browser();

        return match ($result?->status) {
            StepStatus::Ok => sprintf(
                'ran (%d tests, %d failed%s)',
                $browser === null ? 0 : $browser->total,
                $browser === null ? 0 : $browser->failures(),
                $browser !== null && $browser->environmentError ? ', environment error — results unreliable' : '',
            ),
            StepStatus::Skipped => 'not run ('.($result->reason === null ? 'skipped' : $result->reason->value).')',
            StepStatus::Error => 'error ('.mb_strimwidth((string) $result->error, 0, 120, '…').')',
            null => 'not part of this run',
        };
    }
}
