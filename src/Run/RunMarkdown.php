<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\Overlap;
use Astro2006\LaravelAudit\Support\MarkdownTable;

/**
 * run.md: frontmatter plus one table per part of the run, for people.
 */
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
        $rows = array_map(fn (StepResult $result): array => [
            $result->step->value,
            $result->status->value,
            round($result->duration, 2).'s',
            $result->reason->value ?? $result->error ?? implode('; ', $result->warnings),
        ], $this->manifest->steps->all());

        return $this->section('Preflight', ['Step', 'Status', 'Duration', 'Notes'], $rows, always: true);
    }

    /** @return list<string> */
    private function metricsSection(): array
    {
        $rows = array_map(fn (Metric $metric): array => [$metric->label, $metric->value], $this->manifest->metrics);

        return $this->section('Metrics', ['Metric', 'Value'], $rows);
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

        return $this->section('Audits', ['Audit', 'Status', 'Findings', 'C/H/M/L/I', 'Repair', ...self::TELEMETRY_HEADERS, 'Prompt'], $rows);
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

        return $this->section('Tasks', ['Task', 'Status', 'Findings', 'Repair', ...self::TELEMETRY_HEADERS, 'Wall clock', 'Prompt'], $rows);
    }

    /** @return list<string> */
    private function findingsSection(): array
    {
        $rows = [];

        foreach ($this->manifest->outcomes as $outcome) {
            foreach ($outcome->findings as $finding) {
                $rows[] = [
                    $finding->key($outcome->audit->value),
                    $finding->id,
                    $finding->check,
                    $finding->severity->value,
                    $finding->confidence->value,
                    implode(', ', array_map(fn (Location $location): string => '`'.$location.'`', $finding->locations)),
                ];
            }
        }

        return $this->section('Findings', ['Key', 'ID', 'Check', 'Severity', 'Confidence', 'Locations'], $rows);
    }

    /** @return list<string> */
    private function rejectedSection(): array
    {
        $rows = [];

        foreach ($this->manifest->outcomes as $outcome) {
            foreach ($outcome->tasks as $task) {
                foreach ($task->rejected as $rejection) {
                    $rows[] = [$task->key, $rejection->id, $rejection->title, $rejection->reason];
                }
            }
        }

        return $this->section('Rejected findings', ['Task', 'ID', 'Title', 'Reason'], $rows, intro: 'Dropped by the harness; they are not in the audit reports. IDs are the task\'s own.');
    }

    /** @return list<string> */
    private function duplicatesSection(): array
    {
        $rows = array_map(fn (Overlap $overlap): array => [
            $overlap->findingA->key($overlap->auditA).', '.$overlap->findingB->key($overlap->auditB),
            $overlap->findingA->id.', '.$overlap->findingB->id,
            '`'.$overlap->location.'`',
        ], $this->manifest->overlaps);

        return $this->section('Possible duplicates', ['Keys', 'IDs', 'Shared location'], $rows);
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

    /**
     * A level-2 section with one table; nothing when there are no rows.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     * @return list<string>
     */
    private function section(string $title, array $headers, array $rows, bool $always = false, ?string $intro = null): array
    {
        if ($rows === [] && ! $always) {
            return [];
        }

        return ['## '.$title, '', ...($intro === null ? [] : [$intro, '']), ...MarkdownTable::lines($headers, $rows), ''];
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
