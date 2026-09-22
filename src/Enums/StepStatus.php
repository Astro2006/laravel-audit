<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum StepStatus: string
{
    case Ok = 'ok';

    case Error = 'error';

    case Skipped = 'skipped';
}
