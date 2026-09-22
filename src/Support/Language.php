<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final class Language
{
    public static function label(string $locale): string
    {
        return match (strtolower(substr($locale, 0, 2))) {
            'de' => 'German (de)',
            'en' => 'English (en)',
            default => $locale,
        };
    }
}
