<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\Overlap;
use Astro2006\LaravelAudit\Reports\Rejection;
use Astro2006\LaravelAudit\Support\Json;
use DateTimeImmutable;

/**
 * run.md (for people) and run.json (for audit:eval): what ran, with which
 * result, cost and findings. Written at the start and updated at the end.
 */
final class RunManifest
{
    public RunStatus $status = RunStatus::Running;

    public ?DateTimeImmutable $finishedAt = null;

    public ?string $preflightFrom = null;

    public bool $preflightComplete = false;

    public GateDecision $gate = GateDecision::NotRun;

    public ?string $browser = null;

    public ?string $model = null;

    /** The run's overview, written by the results agent once every selected audit is done. */
    public ?string $overview = null;

    public StepResults $steps;

    /** @var list<Metric> */
    public array $metrics = [];

    /** @var list<AuditOutcome> */
    public array $outcomes = [];

    /** @var list<Overlap> */
    public array $overlaps = [];

    public function __construct(
        public readonly AuditRun $run,
        public readonly RunSelection $selection,
        public readonly GitInfo $git,
        public readonly string $agent,
        public readonly string $packageVersion,
    ) {
        $this->steps = new StepResults;
    }

    public function finish(RunStatus $status): void
    {
        $this->status = $status;
        $this->finishedAt = new DateTimeImmutable;
    }

    public function write(): void
    {
        file_put_contents($this->run->manifestPath(), $this->render());
        Json::writeFile($this->run->dir().'/run.json', $this->toArray());
    }

    /**
     * Machine-readable twin of run.md, used by audit:eval.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->run->id,
            'status' => $this->status->value,
            'audits' => $this->selection->auditValues(),
            'without' => $this->selection->withoutValues(),
            'preflight_from' => $this->preflightFrom,
            'gate' => $this->gate->value,
            'agent' => $this->agent,
            'model' => $this->model,
            'overview' => $this->overview,
            'git' => ['branch' => $this->git->branch, 'commit' => $this->git->commit, 'dirty' => $this->git->dirty],
            'outcomes' => array_map(fn (AuditOutcome $outcome): array => [
                'audit' => $outcome->audit->value,
                'status' => $outcome->status->value,
                'reason' => $outcome->reason,
                'repaired' => $outcome->repaired,
                'prompt_hash' => $outcome->promptHash,
                'model' => $outcome->model,
                'telemetry' => $outcome->telemetry->toArray(),
                'findings' => array_map(fn (Finding $finding): array => [
                    'key' => $finding->key($outcome->audit->value),
                    ...$finding->toArray(),
                    'targets' => array_values(array_unique(array_map(fn (Location $location): string => $location->target(), $finding->locations))),
                ], $outcome->findings),
                'errors' => $outcome->errors,
                'warnings' => $outcome->warnings,
                'tasks' => array_map(fn (TaskOutcome $task): array => [
                    'key' => $task->key,
                    'status' => $task->status->value,
                    'reason' => $task->reason,
                    'repaired' => $task->repaired,
                    'prompt_hash' => $task->promptHash,
                    'model' => $task->model,
                    'findings' => count($task->findings),
                    'telemetry' => $task->telemetry->toArray(),
                    'wall_clock_seconds' => $this->wallClock($task),
                    'errors' => $task->errors,
                    'rejected' => array_map(fn (Rejection $rejection): array => $rejection->toArray(), $task->rejected),
                ], $outcome->tasks),
            ], $this->outcomes),
            'overlaps' => count($this->overlaps),
        ];
    }

    public function render(): string
    {
        $frontmatter = [
            'format' => '1',
            'run_id' => $this->run->id,
            'started_at' => $this->run->startedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM) ?? '',
            'status' => $this->status->value,
            'audits' => implode(', ', $this->selection->auditValues()),
            'without' => implode(', ', $this->selection->withoutValues()),
            'preflight' => $this->preflightComplete ? 'complete' : 'incomplete',
            'preflight_from' => $this->preflightFrom ?? '',
            'gate' => $this->gate->value,
            'browser' => $this->browser ?? '',
            'agent' => $this->agent,
            'model' => $this->model ?? '',
            'package_version' => $this->packageVersion,
            'git_branch' => $this->git->branch ?? '',
            'git_commit' => $this->git->commit ?? '',
            'git_dirty' => $this->git->dirty === null ? '' : ($this->git->dirty ? 'true' : 'false'),
        ];

        $lines = ['---'];

        foreach ($frontmatter as $key => $value) {
            $lines[] = rtrim($key.': '.$value);
        }

        array_push($lines, '---', '', '# Run '.$this->run->id, '');

        array_push(
            $lines,
            ...$this->overviewSection(),
            ...$this->preflightSection(),
            ...$this->metricsSection(),
            ...$this->auditsSection(),
            ...$this->tasksSection(),
            ...$this->findingsSection(),
            ...$this->rejectedSection(),
            ...$this->duplicatesSection(),
            ...$this->warningsSection(),
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<Finding>  $findings
     * @return array{critical: int, high: int, medium: int, low: int, info: int}
     */
    public static function severityCounts(array $findings): array
    {
        $count = fn (string $severity): int => count(array_filter($findings, fn (Finding $finding): bool => $finding->severity->value === $severity));

        return ['critical' => $count('critical'), 'high' => $count('high'), 'medium' => $count('medium'), 'low' => $count('low'), 'info' => $count('info')];
    }

    /** @return list<string> */
    private function overviewSection(): array
    {
        return $this->overview === null ? [] : ['## Overview', '', $this->overview, ''];
    }

    /** @return list<string> */
    private function preflightSection(): array
    {
        $lines = ['## Preflight', '', '| Step | Status | Duration | Notes |', '|---|---|---|---|'];

        foreach ($this->steps->all() as $result) {
            $lines[] = sprintf(
                '| %s | %s | %ss | %s |',
                $result->step->value,
                $result->status->value,
                round($result->duration, 2),
                $this->cell($this->notes($result)),
            );
        }

        $lines[] = '';

        return $lines;
    }

    private function notes(StepResult $result): string
    {
        return $result->reason->value ?? $result->error ?? implode('; ', $result->warnings);
    }

    /** @return list<string> */
    private function metricsSection(): array
    {
        if ($this->metrics === []) {
            return [];
        }

        $lines = ['## Metrics', '', '| Metric | Value |', '|---|---|'];

        foreach ($this->metrics as $metric) {
            $lines[] = sprintf('| %s | %s |', $metric->label, $metric->value);
        }

        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function auditsSection(): array
    {
        if ($this->outcomes === []) {
            return [];
        }

        $lines = [
            '## Audits',
            '',
            '| Audit | Status | Findings | C/H/M/L/I | Repair | Turns | Input | Output | Cache read | Cost USD | Duration | Prompt |',
            '|---|---|---|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($this->outcomes as $outcome) {
            $t = $outcome->telemetry;
            $lines[] = sprintf(
                '| %s | %s | %d | %s | %s | %d | %d | %d | %d | %s | %ss | %s |',
                $outcome->audit->value,
                $outcome->status->value.($outcome->reason === null ? '' : ': '.$this->cell($outcome->reason)),
                count($outcome->findings),
                implode('/', self::severityCounts($outcome->findings)),
                $outcome->repaired ? 'yes' : 'no',
                $t->turns,
                $t->inputTokens,
                $t->outputTokens,
                $t->cacheReadTokens,
                $t->costUsd === null ? '–' : number_format($t->costUsd, 2),
                round($t->durationMs / 1000, 1),
                $outcome->promptHash,
            );
        }

        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function tasksSection(): array
    {
        $rows = [];

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->tasks as $task) {
                $t = $task->telemetry;
                $rows[] = sprintf(
                    '| %s | %s | %d | %s | %d | %d | %d | %d | %s | %ss | %ss | %s |',
                    $task->key,
                    $task->status->value.($task->reason === null ? '' : ': '.$this->cell($task->reason)),
                    count($task->findings),
                    $task->repaired ? 'yes' : 'no',
                    $t->turns,
                    $t->inputTokens,
                    $t->outputTokens,
                    $t->cacheReadTokens,
                    $t->costUsd === null ? '–' : number_format($t->costUsd, 2),
                    round($t->durationMs / 1000, 1),
                    $this->wallClock($task),
                    $task->promptHash,
                );
            }
        }

        return $rows === [] ? [] : [
            '## Tasks',
            '',
            '| Task | Status | Findings | Repair | Turns | Input | Output | Cache read | Cost USD | Duration | Wall clock | Prompt |',
            '|---|---|---|---|---|---|---|---|---|---|---|---|',
            ...$rows,
            '',
        ];
    }

    /** @return list<string> */
    private function findingsSection(): array
    {
        $rows = [];

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->findings as $finding) {
                $rows[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s |',
                    $finding->key($outcome->audit->value),
                    $finding->id,
                    $finding->check,
                    $finding->severity->value,
                    $finding->confidence->value,
                    $this->cell(implode(', ', array_map(fn (Location $location): string => '`'.$location.'`', $finding->locations))),
                );
            }
        }

        return $rows === [] ? [] : [
            '## Findings',
            '',
            '| Key | ID | Check | Severity | Confidence | Locations |',
            '|---|---|---|---|---|---|',
            ...$rows,
            '',
        ];
    }

    /** Seconds from the start of the first attempt to the end of the last, repair included. */
    private function wallClock(TaskOutcome $task): float
    {
        return round(max(0.0, $task->finishedAt - $task->startedAt), 1);
    }

    /** @return list<string> */
    private function rejectedSection(): array
    {
        $rows = [];

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->tasks as $task) {
                foreach ($task->rejected as $rejection) {
                    $rows[] = sprintf('| %s | %s | %s | %s |', $task->key, $rejection->id, $this->cell($rejection->title), $this->cell($rejection->reason));
                }
            }
        }

        return $rows === [] ? [] : [
            '## Rejected findings',
            '',
            'Dropped by the harness; they are not in the audit reports. IDs are the task\'s own.',
            '',
            '| Task | ID | Title | Reason |',
            '|---|---|---|---|',
            ...$rows,
            '',
        ];
    }

    /** @return list<string> */
    private function duplicatesSection(): array
    {
        if ($this->overlaps === []) {
            return [];
        }

        $lines = ['## Possible duplicates', '', '| Keys | IDs | Shared location |', '|---|---|---|'];

        foreach ($this->overlaps as $overlap) {
            $lines[] = sprintf(
                '| %s, %s | %s, %s | `%s` |',
                $overlap->findingA->key($overlap->auditA),
                $overlap->findingB->key($overlap->auditB),
                $overlap->findingA->id,
                $overlap->findingB->id,
                $overlap->location,
            );
        }

        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function warningsSection(): array
    {
        $warnings = [];

        foreach ($this->outcomes as $outcome) {
            foreach ([...$outcome->warnings, ...$outcome->errors] as $message) {
                $warnings[] = '- '.$outcome->audit->value.': '.$message;
            }

            // Errors of failed tasks; completed tasks are covered by the audit's own lint.
            foreach ($outcome->tasks as $task) {
                foreach ($task->completed() ? [] : $task->errors as $message) {
                    $warnings[] = '- '.$task->key.': '.$message;
                }
            }
        }

        return $warnings === [] ? [] : ['## Linter warnings', '', ...$warnings, ''];
    }

    private function cell(string $value): string
    {
        return str_replace(["\n", '|'], [' ', '\\|'], $value);
    }
}
