<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

final readonly class Metric
{
    public function __construct(
        public string $label,
        public string $value,
    ) {}
}
