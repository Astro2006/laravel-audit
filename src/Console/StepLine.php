<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

final readonly class StepLine
{
    public function __construct(
        public string $symbol,
        public string $color,
        public string $label,
        public string $detail,
    ) {}
}
