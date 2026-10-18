<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;

final class FrontendAudit extends Audit
{
    public function type(): AuditType
    {
        return AuditType::Frontend;
    }

    public function idPrefix(): string
    {
        return 'FE';
    }

    public function title(): string
    {
        return 'Frontend, Routes & UI/UX Audit';
    }
}
