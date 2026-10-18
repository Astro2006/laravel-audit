<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum OptionalStep: string
{
    case Browser = 'browser';
    case Phpstan = 'phpstan';
    case Pint = 'pint';
    case Coverage = 'coverage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
