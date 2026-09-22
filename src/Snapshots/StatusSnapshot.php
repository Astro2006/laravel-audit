<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;

/** Which preflight steps ran, which were skipped and which failed. */
final class StatusSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::Status;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $results = $preflight->steps->all();

        if ($results === []) {
            return null;
        }

        $count = fn (StepStatus $status): int => count(array_filter($results, fn (StepResult $result): bool => $result->status === $status));

        return new SnapshotManifest(
            SnapshotType::Status,
            'Preflight steps',
            [
                $count(StepStatus::Ok).' ok',
                $count(StepStatus::Skipped).' skipped',
                $count(StepStatus::Error).' error',
                'gate: '.$preflight->gate->value,
            ],
            ['Step', 'Status', 'Note'],
            array_map(fn (StepResult $result): SnapshotRow => new SnapshotRow(
                [$result->step->value, $result->status->value, $result->reason->value ?? ($result->error === null ? implode(', ', $result->warnings) : 'see details')],
                $result->error === null ? null : Details::text($result->error),
            ), $results),
        );
    }
}
