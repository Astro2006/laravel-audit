<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * A level-3 heading inside "## Findings" that is not a valid finding heading.
 */
final readonly class InvalidHeading
{
    public function __construct(
        public int $line,
        public string $text,
    ) {}
}
