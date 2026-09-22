<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

/**
 * One row of a snapshot table. The cells stay short (names, counts, paths);
 * everything longer goes into the details, written to the row's own file.
 */
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
