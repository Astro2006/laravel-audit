<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum GateMode: string
{
    case Ask = 'ask';
    case Abort = 'abort';
    case Continue = 'continue';
}
