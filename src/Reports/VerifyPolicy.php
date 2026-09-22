<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Which findings a verify job checks. The more severe a finding, the more a
 * wrong one costs; the surer the agent is, the less it needs a second look:
 *
 *              confirmed  likely  suspected
 *   critical   yes        yes     yes
 *   high       no         yes     yes
 *   medium     no         no      yes
 *   low, info  no         no      no
 */
final class VerifyPolicy
{
    /** Severity rank minus confidence rank from which a finding is verified. */
    private const int THRESHOLD = 2;

    public static function needsVerify(Finding $finding): bool
    {
        return $finding->severity->rank() - $finding->confidence->rank() >= self::THRESHOLD;
    }
}
