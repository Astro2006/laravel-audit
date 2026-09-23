<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;

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
        $notable = array_values(array_filter($results, fn (StepResult $result): bool => $result->status !== StepStatus::Ok || $result->warnings !== []));

        return new SnapshotManifest(
            SnapshotType::Status,
            'Preflight steps',
            [
                $count(StepStatus::Ok).' ok ('.implode(', ', array_map(fn (StepResult $result): string => $result->step->value, array_filter($results, fn (StepResult $result): bool => $result->status === StepStatus::Ok))).')',
                $count(StepStatus::Skipped).' skipped',
                $count(StepStatus::Error).' error',
                'gate: '.$preflight->gate->value,
            ],
            ['Step', 'Status', 'Note'],
            array_map(fn (StepResult $result): SnapshotRow => new SnapshotRow(
                [$result->step->value, $result->status->value, $result->reason->value ?? ($result->error === null ? implode(', ', $result->warnings) : 'see details')],
                $result->error === null ? null : Details::text($result->error),
            ), $notable),
        );
    }
}
