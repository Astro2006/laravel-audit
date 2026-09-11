<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum RunStatus: string
{
    case Running = 'running';
    case Aborted = 'aborted';
    case Failed = 'failed';
    case Completed = 'completed';
    case PreflightOnly = 'preflight-only';
}
