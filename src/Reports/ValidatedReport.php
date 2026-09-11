<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class ValidatedReport
{
    public function __construct(
        public string $body,
        public LintResult $lint,
    ) {}
}
