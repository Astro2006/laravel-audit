<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum Effort: string
{
    case S = 'S';
    case M = 'M';
    case L = 'L';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
