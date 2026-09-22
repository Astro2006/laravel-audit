<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

/**
 * An extra detail file of a snapshot that belongs to no single row, e.g. the
 * list of skipped tests or the full route table.
 */
final readonly class SnapshotFile
{
    public function __construct(
        public string $name,
        public string $label,
        public string $content,
    ) {}
}
