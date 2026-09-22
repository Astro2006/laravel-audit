<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Rejection;

/**
 * The result of one task: its validated report body and findings (with the
 * task's own IDs; the audit report renumbers them).
 */
final readonly class TaskOutcome
{
    /** Key suffix of the verify job that checks a task's findings. */
    public const string VERIFY_SUFFIX = '.verify';

    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $errors  validation errors left after the repair pass
     * @param  list<string>  $warnings
     * @param  float  $startedAt  microtime of the first attempt
     * @param  list<Rejection>  $rejected  findings the harness dropped
     */
    public function __construct(
        public string $key,
        public ?AuditType $audit,
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
        public array $rejected = [],
    ) {}

    /**
     * The outcome after the verify pass: dropped findings leave the body and
     * the findings, downgraded ones lose one severity step and confidence.
     *
     * @param  list<string>  $dropped  finding IDs
     * @param  list<string>  $downgraded  finding IDs
     * @param  list<Rejection>  $rejected
     */
    public function withVerdicts(string $body, array $dropped, array $downgraded, array $rejected): self
    {
        return new self(
            $this->key, $this->audit, $this->status, $this->reason, $body,
            array_values(array_map(
                fn (Finding $finding): Finding => in_array($finding->id, $downgraded, true) ? $finding->downgraded() : $finding,
                array_filter($this->findings, fn (Finding $finding): bool => ! in_array($finding->id, $dropped, true)),
            )),
            $this->errors, $this->warnings, $this->telemetry, $this->repaired, $this->promptHash, $this->model,
            $this->startedAt, $this->finishedAt, [...$this->rejected, ...$rejected],
        );
    }

    public function isVerify(): bool
    {
        return str_ends_with($this->key, self::VERIFY_SUFFIX);
    }

    public function completed(): bool
    {
        return $this->status === AuditStatus::Completed;
    }

    /** The name part of the key: backend.injection → injection. Jobs without an audit (e.g. results) return the key unchanged. */
    public function name(): string
    {
        return $this->audit === null ? $this->key : substr($this->key, strlen($this->audit->value) + 1);
    }
}
