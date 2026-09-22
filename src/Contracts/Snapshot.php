<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Snapshots\SnapshotManifest;

interface Snapshot
{
    public function type(): SnapshotType;

    /**
     * Null when the preflight has no data of this kind (step skipped, failed or not selected).
     *
     * @param  string  $displayDir  the preflight directory as the agent sees it, for paths in details
     */
    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest;
}
