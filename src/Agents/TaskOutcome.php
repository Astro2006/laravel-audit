<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Rejection;
use Astro2006\LaravelAudit\Reports\ValidatedReport;

final readonly class TaskOutcome
{
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

    public static function immediate(AgentJob $job, AuditStatus $status, string $reason): self
    {
        $now = microtime(true);

        return new self($job->key, $job->audit, $status, $reason, null, [], [], [], new Telemetry, false, $job->promptHash, null, $now, $now);
    }

    public static function fromAttempt(Attempt $attempt, AuditStatus $status, ?string $reason, ?ValidatedReport $report, Telemetry $telemetry, AgentResult $result): self
    {
        $job = $attempt->job;

        return new self(
            key: $job->key,
            audit: $job->audit,
            status: $status,
            reason: $reason,
            body: $report?->body,
            findings: $report === null ? [] : $report->lint->findings,
            errors: $report === null ? [] : $report->lint->errors,
            warnings: $report === null ? [] : $report->lint->warnings,
            telemetry: $telemetry,
            repaired: $attempt->repair,
            promptHash: $job->promptHash,
            model: $result->model,
            startedAt: $attempt->jobStartedAt,
            finishedAt: microtime(true),
            rejected: $report === null ? [] : $report->lint->rejected,
        );
    }

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

    public function name(): string
    {
        return $this->audit === null ? $this->key : substr($this->key, strlen($this->audit->value) + 1);
    }
}
