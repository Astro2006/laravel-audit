<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;

final class TestsSnapshot extends JunitSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::Tests;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'tests/tests-condensed.json');

        return $report === null ? null : $this->manifest('Tests', $report);
    }
}
