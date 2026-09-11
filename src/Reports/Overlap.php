<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class Overlap
{
    public function __construct(
        public string $auditA,
        public Finding $findingA,
        public string $auditB,
        public Finding $findingB,
        public Location $location,
    ) {}
}
