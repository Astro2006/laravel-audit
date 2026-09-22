<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Reports\Location;

/**
 * run.md: frontmatter plus one table per part of the run, for people.
 */
final class RunMarkdown
{
    public function __construct(private readonly RunManifest $manifest) {}

    public function render(): string
    {
        $frontmatter = [
            'format' => '1',
            'run_id' => $this->manifest->run->id,
            'started_at' => $this->manifest->run->startedAt->format(DATE_ATOM),
            'finished_at' => $this->manifest->finishedAt?->format(DATE_ATOM) ?? '',
            'status' => $this->manifest->status->value,
            'audits' => implode(', ', $this->manifest->selection->auditValues()),
            'without' => implode(', ', $this->manifest->selection->withoutValues()),
            'preflight' => $this->manifest->preflightComplete ? 'complete' : 'incomplete',
            'preflight_from' => $this->manifest->preflightFrom ?? '',
            'gate' => $this->manifest->gate->value,
            'browser' => $this->manifest->browser ?? '',
            'agent' => $this->manifest->agent,
            'model' => $this->manifest->model ?? '',
            'package_version' => $this->manifest->packageVersion,
            'git_branch' => $this->manifest->git->branch ?? '',
            'git_commit' => $this->manifest->git->commit ?? '',
            'git_dirty' => $this->manifest->git->dirty === null ? '' : ($this->manifest->git->dirty ? 'true' : 'false'),
        ];

        $lines = ['---'];

        foreach ($frontmatter as $key => $value) {
            $lines[] = rtrim($key.': '.$value);
        }

        array_push($lines, '---', '', '# Run '.$this->manifest->run->id, '');

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

    /** @return list<string> */
    private function overviewSection(): array
    {
        return $this->manifest->overview === null ? [] : ['## Overview', '', $this->manifest->overview, ''];
    }

    /** @return list<string> */
    private function preflightSection(): array
    {
        $lines = ['## Preflight', '', '| Step | Status | Duration | Notes |', '|---|---|---|---|'];

        foreach ($this->manifest->steps->all() as $result) {
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
        if ($this->manifest->metrics === []) {
            return [];
        }

        $lines = ['## Metrics', '', '| Metric | Value |', '|---|---|'];

        foreach ($this->manifest->metrics as $metric) {
            $lines[] = sprintf('| %s | %s |', $metric->label, $metric->value);
        }

        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function auditsSection(): array
    {
        if ($this->manifest->outcomes === []) {
            return [];
        }

        $lines = [
            '## Audits',
            '',
            '| Audit | Status | Findings | C/H/M/L/I | Repair | Turns | Input | Output | Cache read | Cost USD | Duration | Prompt |',
            '|---|---|---|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($this->manifest->outcomes as $outcome) {
            $t = $outcome->telemetry;
            $lines[] = sprintf(
                '| %s | %s | %d | %s | %s | %d | %d | %d | %d | %s | %ss | %s |',
                $outcome->audit->value,
                $outcome->status->value.($outcome->reason === null ? '' : ': '.$this->cell($outcome->reason)),
                count($outcome->findings),
                implode('/', RunManifest::severityCounts($outcome->findings)),
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

        foreach ($this->manifest->outcomes as $outcome) {
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
                    RunManifest::wallClock($task),
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

        foreach ($this->manifest->outcomes as $outcome) {
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

    /** @return list<string> */
    private function rejectedSection(): array
    {
        $rows = [];

        foreach ($this->manifest->outcomes as $outcome) {
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
        if ($this->manifest->overlaps === []) {
            return [];
        }

        $lines = ['## Possible duplicates', '', '| Keys | IDs | Shared location |', '|---|---|---|'];

        foreach ($this->manifest->overlaps as $overlap) {
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

        foreach ($this->manifest->outcomes as $outcome) {
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
