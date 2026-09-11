<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\Finding;

/**
 * The result of one task: its validated report body and findings (with the
 * task's own IDs; the audit report renumbers them).
 */
final readonly class TaskOutcome
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $errors  validation errors left after the repair pass
     * @param  list<string>  $warnings
     * @param  float  $startedAt  microtime of the first attempt
     */
    public function __construct(
        public string $key,
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
        public float $startedAt = 0.0,
        public float $finishedAt = 0.0,
    ) {}

    public function completed(): bool
    {
        return $this->status === AuditStatus::Completed;
    }

    /** The name part of the key: backend.injection → injection. */
    public function name(): string
    {
        return substr($this->key, strlen($this->audit->value) + 1);
    }
}
