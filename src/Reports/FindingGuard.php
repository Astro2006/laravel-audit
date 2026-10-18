<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

interface FindingGuard
{
    public function reject(Finding $finding): ?string;
}
