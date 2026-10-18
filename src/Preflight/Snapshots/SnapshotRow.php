<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

final readonly class SnapshotRow
{
    /**
     * @param  list<string>  $cells
     * @param  string|null  $details  markdown; null = the row has no detail file
     */
    public function __construct(
        public array $cells,
        public ?string $details = null,
    ) {}
}
