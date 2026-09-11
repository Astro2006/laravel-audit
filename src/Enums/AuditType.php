<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

use Astro2006\LaravelAudit\Contracts\Audit;
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

    public function audit(): Audit
    {
        return match ($this) {
            self::Tests => new TestsAudit,
            self::Frontend => new FrontendAudit,
            self::Backend => new BackendAudit,
            self::Dependencies => new DependenciesAudit,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
