<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Info => 0,
        };
    }

    public function lower(): self
    {
        return match ($this) {
            self::Critical => self::High,
            self::High => self::Medium,
            self::Medium => self::Low,
            self::Low, self::Info => self::Info,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
