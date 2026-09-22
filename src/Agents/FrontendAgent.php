<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditType;

final class FrontendAgent extends Agent
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
