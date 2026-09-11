<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum AuditStatus: string
{
    case Completed = 'completed';

    /** Some tasks failed; the report covers the checks of the others. */
    case Partial = 'partial';

    case Failed = 'failed';
}
