<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum AuditExit: int
{
    case Ok = 0;

    case GateAborted = 2;

    case HarnessError = 3;

    case Paused = 4;
}
