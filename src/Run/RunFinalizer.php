<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\OverlapDetector;
use Astro2006\LaravelAudit\Reports\ReportWriter;

/**
 * Writes the reports and completes run.md after the agents are done.
 */
final class RunFinalizer
{
    public function __construct(
        private readonly RiskPoints $riskPoints,
        private readonly ReportWriter $writer = new ReportWriter,
        private readonly OverlapDetector $overlaps = new OverlapDetector,
        private readonly Metrics $metrics = new Metrics,
    ) {}

    /** @param list<AuditOutcome> $outcomes */
    public function finalize(RunManifest $manifest, PreflightSummary $summary, array $outcomes, string $agent): void
    {
        $run = $manifest->run;
        $findingsByAudit = [];
        $findingTasks = [];
        $all = [];

        foreach ($outcomes as $outcome) {
            $audit = $outcome->audit->value;

            foreach ($outcome->tasks as $task) {
                if (! $task->completed() && $task->body !== null) {
                    // Kept for inspection; not part of the report.
                    file_put_contents($run->logsDir().'/'.$task->key.'.invalid.md', $task->body);
                }
            }

            if ($outcome->body !== null) {
                $this->writer->write($run->reportPath($audit), [
                    'format' => 1,
                    'audit' => $audit,
                    'run_id' => $run->id,
                    'agent' => $agent,
                    'model' => $outcome->model,
                    'prompt_hash' => $outcome->promptHash,
                    'generated_at' => date(DATE_ATOM),
                ], $outcome->audit->audit()->title().' — '.$run->id, $outcome->body);

                $findingsByAudit = [...$findingsByAudit, $audit => $outcome->findings];
                $findingTasks = [...$findingTasks, $audit => $outcome->findingTasks];
                array_push($all, ...$outcome->findings);
            }

            $manifest->model ??= $outcome->model;
        }

        $manifest->outcomes = $outcomes;
        $manifest->overlaps = $this->overlaps->detect($findingsByAudit, $findingTasks);
        $manifest->metrics = $this->metrics->rows($summary, $this->riskPoints->total($all));

        $failed = array_filter($outcomes, fn (AuditOutcome $outcome): bool => ! $outcome->completed());
        $manifest->finish($failed === [] ? RunStatus::Completed : RunStatus::Failed);
        $manifest->write();
    }
}
