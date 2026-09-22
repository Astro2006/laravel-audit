<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final class VerifyPolicy
{
    private const int THRESHOLD = 2;

    public static function needsVerify(Finding $finding): bool
    {
        return $finding->severity->rank() - $finding->confidence->rank() >= self::THRESHOLD;
    }
}
