<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

use Astro2006\LaravelAudit\Audits\Audit;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Audits\FrontendAudit;
use Astro2006\LaravelAudit\Audits\TestsAudit;

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
            self::Tests => new TestsAudit($overridePath),
            self::Frontend => new FrontendAudit($overridePath),
            self::Backend => new BackendAudit($overridePath),
            self::Dependencies => new DependenciesAudit($overridePath),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
