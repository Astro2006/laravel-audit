<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

/**
 * Exit code of `php artisan audit`.
 */
enum AuditExit: int
{
    case Ok = 0;

    /** The test gate stopped the run before the agents. */
    case GateAborted = 2;

    /** Setup, preflight or an agent task failed. */
    case HarnessError = 3;
}
