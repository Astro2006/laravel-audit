<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum StepStatus: string
{
    /** The tool produced usable output (even if it reported problems). */
    case Ok = 'ok';

    /** The tool ran but produced no usable output. */
    case Error = 'error';

    case Skipped = 'skipped';
}
