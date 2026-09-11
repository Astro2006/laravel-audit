<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Illuminate\Support\Arr;

/**
 * Read access to preflight/summary.json.
 */
final readonly class PreflightSummary
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public ?string $directory = null,
    ) {}

    public static function fromDirectory(string $preflightDir): self
    {
        $path = $preflightDir.'/summary.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return new self(is_array($data) ? $data : [], $preflightDir);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /** @return array<string, mixed> */
    public function step(PreflightStep $step): array
    {
        $data = $this->get('steps.'.$step->value, []);

        return is_array($data) ? $data : [];
    }

    public function status(PreflightStep $step): ?StepStatus
    {
        return StepStatus::tryFrom((string) ($this->step($step)['status'] ?? ''));
    }

    public function ran(PreflightStep $step): bool
    {
        return $this->status($step) === StepStatus::Ok;
    }

    /**
     * Decodes a JSON file below the preflight directory.
     *
     * @return array<mixed>
     */
    public function json(string $relativePath): array
    {
        if ($this->directory === null || ! is_file($this->directory.'/'.$relativePath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->directory.'/'.$relativePath), true);

        return is_array($data) ? $data : [];
    }
}
