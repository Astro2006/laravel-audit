<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class InvalidHeading
{
    public function __construct(
        public int $line,
        public string $text,
    ) {}
}
