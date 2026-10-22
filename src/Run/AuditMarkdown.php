<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\Overlap;
use Astro2006\LaravelAudit\Support\MarkdownTable;

final class AuditMarkdown
{
    public function __construct(private readonly RunManifest $manifest) {}

    public function render(): string
    {
        $reports = array_map(fn (AuditOutcome $outcome): string => '`'.$outcome->audit->value.'.md`', $this->manifest->outcomes);

        $lines = [
            '# Audit '.$this->manifest->run->id,
            '',
            sprintf('The findings of this run. Each one is written up in %s; the preflight, the tasks and their cost are in `run.md`.', implode(', ', $reports)),
            '',
            ...$this->overviewSection(),
            ...$this->auditsSection(),
            ...$this->findingsSection(),
            ...$this->duplicatesSection(),
            ...$this->rejectedSection(),
        ];

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function overviewSection(): array
    {
        return $this->manifest->overview === null ? [] : ['## Overview', '', $this->manifest->overview, ''];
    }

    /** @return list<string> */
    private function auditsSection(): array
    {
        $rows = array_map(fn (AuditOutcome $outcome): array => [
            $outcome->audit->value,
            $outcome->status->value.($outcome->reason === null ? '' : ': '.$outcome->reason),
            count($outcome->findings),
            implode('/', RunManifest::severityCounts($outcome->findings)),
        ], $this->manifest->outcomes);

        return MarkdownTable::section('Audits', ['Audit', 'Status', 'Findings', 'C/H/M/L/I'], $rows);
    }

    /** @return list<string> */
    private function findingsSection(): array
    {
        $rows = [];

        foreach ($this->manifest->outcomes as $outcome) {
            foreach ($outcome->findings as $finding) {
                $rows[] = [
                    $finding->id,
                    $finding->severity->value,
                    $finding->confidence->value,
                    $finding->title,
                    implode(', ', array_map(fn (Location $location): string => '`'.$location.'`', $finding->locations)),
                    $finding->key($outcome->audit->value),
                ];
            }
        }

        return MarkdownTable::section('Findings', ['ID', 'Severity', 'Confidence', 'Title', 'Locations', 'Key'], $rows);
    }

    /** @return list<string> */
    private function duplicatesSection(): array
    {
        $rows = array_map(fn (Overlap $overlap): array => [
            $overlap->findingA->id.', '.$overlap->findingB->id,
            '`'.$overlap->location.'`',
            $overlap->findingA->key($overlap->auditA).', '.$overlap->findingB->key($overlap->auditB),
        ], $this->manifest->overlaps);

        return MarkdownTable::section('Possible duplicates', ['IDs', 'Shared location', 'Keys'], $rows);
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

        return MarkdownTable::section('Rejected findings', ['Task', 'ID', 'Title', 'Reason'], $rows, intro: 'Dropped by the harness; they are not in the audit reports. IDs are the task\'s own.');
    }
}
