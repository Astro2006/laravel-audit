<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum Stage: string
{
    case Analyse = 'analyse';
    case Standards = 'standards';
    case Security = 'security';

    public function label(): string
    {
        return match ($this) {
            self::Analyse => 'Analyse',
            self::Standards => 'Coding standards',
            self::Security => 'Security',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $stage): string => $stage->value, self::cases());
    }
}
