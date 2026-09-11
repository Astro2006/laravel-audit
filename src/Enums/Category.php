<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum Category: string
{
    case Security = 'security';
    case Correctness = 'correctness';
    case Performance = 'performance';
    case Maintainability = 'maintainability';
    case Testing = 'testing';
    case Ux = 'ux';
    case Accessibility = 'accessibility';
    case Consistency = 'consistency';
    case DeadCode = 'dead-code';
    case Dependency = 'dependency';
    case Configuration = 'configuration';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
