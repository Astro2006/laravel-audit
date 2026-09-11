<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\Finding;

final readonly class AuditOutcome
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $errors  validation errors left after the repair pass
     * @param  list<string>  $warnings
     */
    public function __construct(
        public AuditType $audit,
        public AuditStatus $status,
        public ?string $reason,
        public ?string $body,
        public array $findings,
        public array $errors,
        public array $warnings,
        public Telemetry $telemetry,
        public bool $repaired,
        public string $promptHash,
        public ?string $model,
    ) {}

    public function completed(): bool
    {
        return $this->status === AuditStatus::Completed;
    }
}
