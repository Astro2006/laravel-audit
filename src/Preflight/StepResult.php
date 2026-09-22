<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Support\Read;

final readonly class StepResult
{
    /**
     * @param  StepDTO|null  $data  goes into summary.json below steps.{step}
     * @param  list<string>  $warnings  warning codes, shown in the console and kept in summary.json
     */
    public function __construct(
        public PreflightStep $step,
        public StepStatus $status,
        public ?SkipReason $reason = null,
        public ?StepDTO $data = null,
        public array $warnings = [],
        public ?string $error = null,
        public float $duration = 0.0,
    ) {}

    /** @param list<string> $warnings */
    public static function ok(PreflightStep $step, StepDTO $data, array $warnings = []): self
    {
        return new self($step, StepStatus::Ok, data: $data, warnings: $warnings);
    }

    public static function error(PreflightStep $step, string $error, ?StepDTO $data = null): self
    {
        return new self($step, StepStatus::Error, data: $data, error: $error);
    }

    /** @param list<string> $warnings */
    public static function skipped(PreflightStep $step, SkipReason $reason, ?StepDTO $data = null, array $warnings = []): self
    {
        return new self($step, StepStatus::Skipped, $reason, $data, $warnings);
    }

    /**
     * Rebuilds a result from summary.json (used by --reuse-preflight).
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(PreflightStep $step, array $data): self
    {
        $class = $step->dtoClass();

        return new self(
            $step,
            StepStatus::tryFrom(Read::string($data, 'status')) ?? StepStatus::Error,
            SkipReason::tryFrom(Read::string($data, 'reason')),
            $class::fromArray($data),
            Read::strings($data, 'warnings'),
            Read::nullableString($data, 'error'),
            Read::float($data, 'duration_s'),
        );
    }

    public function withDuration(float $seconds): self
    {
        return new self($this->step, $this->status, $this->reason, $this->data, $this->warnings, $this->error, $seconds);
    }

    public function isOk(): bool
    {
        return $this->status === StepStatus::Ok;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            'error' => $this->error,
            'duration_s' => round($this->duration, 2),
            'warnings' => $this->warnings === [] ? null : $this->warnings,
            ...($this->data?->toArray() ?? []),
        ], fn (mixed $value): bool => $value !== null);
    }
}
