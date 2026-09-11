<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class ReportLine
{
    public function __construct(
        public int $number,
        public string $text,
        public bool $inFence,
    ) {}
}
