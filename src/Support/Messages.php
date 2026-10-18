<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final class Messages
{
    public function __construct(public readonly string $locale = 'en') {}

    public static function fromConfig(): self
    {
        $locale = (string) (config('audit.report_language') ?: app()->getLocale());

        return new self(strtolower(substr($locale, 0, 2)) ?: 'en');
    }

    /** @param array<string, string|int|float|null> $replace */
    public function get(string $key, array $replace = []): string
    {
        $value = trans('audit::messages.'.$key, array_map(fn ($value): string => (string) $value, $replace), $this->locale);

        return is_string($value) ? $value : $key;
    }
}
