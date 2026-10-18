<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;

final class BackendAudit extends Audit
{
    public function type(): AuditType
    {
        return AuditType::Backend;
    }

    public function idPrefix(): string
    {
        return 'BE';
    }

    public function title(): string
    {
        return 'Backend Audit';
    }
}
