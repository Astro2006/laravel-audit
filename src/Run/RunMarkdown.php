<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Preflight\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Preflight\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\MarkdownTable;

final class RunMarkdown
{
    private const array TELEMETRY_HEADERS = ['Turns', 'Input', 'Output', 'Cache read', 'Cost USD', 'Duration'];

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

        if ($this->manifest->outcomes !== []) {
            array_push($lines, 'What the run did and what it cost. The overview and the findings are in `audit.md`.', '');
        }

        array_push(
            $lines,
            ...$this->preflightSection(),
            ...$this->metricsSection(),
            ...$this->auditsSection(),
            ...$this->tasksSection(),
            ...$this->warningsSection(),
        );

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function preflightSection(): array
    {
        $rows = array_map(fn (StepResult $result): array => [
            $result->step->value,
            $this->stepStatus($result),
            round($result->duration, 2).'s',
            $result->reason->value ?? $result->error ?? implode('; ', $result->warnings),
        ], $this->manifest->steps->all());

        return MarkdownTable::section('Preflight', ['Step', 'Status', 'Duration', 'Notes'], $rows, always: true);
    }

    private function stepStatus(StepResult $result): string
    {
        $data = $result->data;
        $failures = $data instanceof TestsDTO || $data instanceof BrowserDTO ? $data->failures() : 0;

        return $result->status->value.($failures > 0 ? sprintf(' (%d failed)', $failures) : '');
    }

    /** @return list<string> */
    private function metricsSection(): array
    {
        $rows = array_map(fn (Metric $metric): array => [$metric->label, $metric->value], $this->manifest->metrics);

        return MarkdownTable::section('Metrics', ['Metric', 'Value'], $rows);
    }

    /** @return list<string> */
    private function auditsSection(): array
    {
        $rows = array_map(fn (AuditOutcome $outcome): array => [
            $outcome->audit->value,
            $outcome->status->value.($outcome->reason === null ? '' : ': '.$outcome->reason),
            count($outcome->findings),
            implode('/', RunManifest::severityCounts($outcome->findings)),
            $outcome->repaired ? 'yes' : 'no',
            ...$this->telemetryCells($outcome->telemetry),
            $outcome->promptHash,
        ], $this->manifest->outcomes);

        return MarkdownTable::section('Audits', ['Audit', 'Status', 'Findings', 'C/H/M/L/I', 'Repair', ...self::TELEMETRY_HEADERS, 'Prompt'], $rows);
    }

    /** @return list<string> */
    private function tasksSection(): array
    {
        $rows = [];

        foreach ($this->manifest->outcomes as $outcome) {
            foreach ($outcome->tasks as $task) {
                $rows[] = [
                    $task->key,
                    $task->status->value.($task->reason === null ? '' : ': '.$task->reason),
                    count($task->findings),
                    $task->repaired ? 'yes' : 'no',
                    ...$this->telemetryCells($task->telemetry),
                    RunManifest::wallClock($task).'s',
                    $task->promptHash,
                ];
            }
        }

        return MarkdownTable::section('Tasks', ['Task', 'Status', 'Findings', 'Repair', ...self::TELEMETRY_HEADERS, 'Wall clock', 'Prompt'], $rows);
    }

    /** @return list<string> */
    private function warningsSection(): array
    {
        $warnings = [];

        foreach ($this->manifest->outcomes as $outcome) {
            foreach ([...$outcome->warnings, ...$outcome->errors] as $message) {
                $warnings[] = '- '.$outcome->audit->value.': '.$message;
            }

            foreach ($outcome->tasks as $task) {
                foreach ($task->completed() ? [] : $task->errors as $message) {
                    $warnings[] = '- '.$task->key.': '.$message;
                }
            }
        }

        return $warnings === [] ? [] : ['## Linter warnings', '', ...$warnings, ''];
    }

    /** @return list<string|int> turns, tokens, cost and duration, in TELEMETRY_HEADERS order */
    private function telemetryCells(Telemetry $telemetry): array
    {
        return [
            $telemetry->turns,
            $telemetry->inputTokens,
            $telemetry->outputTokens,
            $telemetry->cacheReadTokens,
            $telemetry->costUsd === null ? '–' : number_format($telemetry->costUsd, 2),
            round($telemetry->durationMs / 1000, 1).'s',
        ];
    }
}
