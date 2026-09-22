<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

use Astro2006\LaravelAudit\Exceptions\InvalidSelection;

enum AgentMode: string
{
    case Single = 'single';
    case Parallel = 'parallel';

    public static function resolve(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? throw InvalidSelection::unknownMode($value, array_column(self::cases(), 'value'));
    }

    public function concurrency(int $parallel): int
    {
        return $this === self::Single ? 1 : max(1, $parallel);
    }
}
