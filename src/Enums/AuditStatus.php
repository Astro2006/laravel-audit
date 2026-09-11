<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum AuditStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
