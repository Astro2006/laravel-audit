<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum Confidence: string
{
    case Confirmed = 'confirmed';
    case Likely = 'likely';
    case Suspected = 'suspected';

    public function rank(): int
    {
        return match ($this) {
            self::Confirmed => 2,
            self::Likely => 1,
            self::Suspected => 0,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
