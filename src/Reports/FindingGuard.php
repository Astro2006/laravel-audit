<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Audit-specific facts a finding must not contradict, e.g. an advisory
 * that composer audit did not report.
 */
interface FindingGuard
{
    /** Why the finding is rejected, or null when it may stay. */
    public function reject(Finding $finding): ?string;
}
