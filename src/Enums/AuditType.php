<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

use Astro2006\LaravelAudit\Agents\BackendAgent;
use Astro2006\LaravelAudit\Agents\DependenciesAgent;
use Astro2006\LaravelAudit\Agents\FrontendAgent;
use Astro2006\LaravelAudit\Agents\TestsAgent;
use Astro2006\LaravelAudit\Contracts\Audit;

enum AuditType: string
{
    case Tests = 'tests';
    case Frontend = 'frontend';
    case Backend = 'backend';
    case Dependencies = 'dependencies';

    /** @param  string|null  $overridePath  published prompts; only matters when actually rendering prompts */
    public function audit(?string $overridePath = null): Audit
    {
        return match ($this) {
            self::Tests => new TestsAgent($overridePath),
            self::Frontend => new FrontendAgent($overridePath),
            self::Backend => new BackendAgent($overridePath),
            self::Dependencies => new DependenciesAgent($overridePath),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
