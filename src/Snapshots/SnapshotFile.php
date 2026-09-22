<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

final readonly class SnapshotFile
{
    public function __construct(
        public string $name,
        public string $label,
        public string $content,
    ) {}
}
