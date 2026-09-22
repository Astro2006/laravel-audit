<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditType;

final class BackendAgent extends Agent
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
