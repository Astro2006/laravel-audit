<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum ReadProtection: string
{
    case Enforced = 'enforced';

    case Waived = 'waived';

    case Missing = 'missing';
}
