<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Enums\StepStatus;

final readonly class StepResult
{
    /**
     * @param  array<string, mixed>  $data  goes into summary.json below steps.{step}
     * @param  list<string>  $warnings  shown in the console and kept in summary.json
     */
    public function __construct(
        public PreflightStep $step,
        public StepStatus $status,
        public ?SkipReason $reason = null,
        public array $data = [],
        public array $warnings = [],
        public ?string $error = null,
        public float $duration = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     */
    public static function ok(PreflightStep $step, array $data = [], array $warnings = []): self
    {
        return new self($step, StepStatus::Ok, data: $data, warnings: $warnings);
    }

    /** @param array<string, mixed> $data */
    public static function error(PreflightStep $step, string $error, array $data = []): self
    {
        return new self($step, StepStatus::Error, data: $data, error: $error);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     */
    public static function skipped(PreflightStep $step, SkipReason $reason, array $data = [], array $warnings = []): self
    {
        return new self($step, StepStatus::Skipped, $reason, $data, $warnings);
    }

    /**
     * Rebuilds a result from summary.json (used by --reuse-preflight).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(PreflightStep $step, array $data): self
    {
        $meta = ['status', 'reason', 'error', 'duration_s', 'warnings'];

        return new self(
            $step,
            StepStatus::tryFrom((string) ($data['status'] ?? '')) ?? StepStatus::Error,
            SkipReason::tryFrom((string) ($data['reason'] ?? '')),
            array_diff_key($data, array_flip($meta)),
            array_values((array) ($data['warnings'] ?? [])),
            isset($data['error']) ? (string) $data['error'] : null,
            (float) ($data['duration_s'] ?? 0),
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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
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
            ...$this->data,
        ], fn ($value): bool => $value !== null);
    }
}
