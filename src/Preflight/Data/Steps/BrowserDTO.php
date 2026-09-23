<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Preflight\Data\Tests\JunitReport;
use Astro2006\LaravelAudit\Preflight\DatabaseGuardResult;
use Astro2006\LaravelAudit\Support\Read;

final readonly class BrowserDTO implements StepDTO
{
    /**
     * @param  string|null  $path  directory of the browser tests
     * @param  'suite'|'path'|null  $mode
     */
    public function __construct(
        public int $total = 0,
        public int $passed = 0,
        public int $failed = 0,
        public int $errors = 0,
        public int $skipped = 0,
        public bool $environmentError = false,
        public ?string $path = null,
        public ?string $mode = null,
        public int $artifacts = 0,
        public ?DatabaseGuardResult $database = null,
    ) {}

    /** @param 'suite'|'path' $mode */
    public static function fromReport(JunitReport $report, string $path, string $mode, int $artifacts): self
    {
        return new self(
            total: $report->total,
            passed: $report->passed,
            failed: $report->failed,
            errors: $report->errors,
            skipped: $report->skipped,
            environmentError: $report->environmentError,
            path: $path,
            mode: $mode,
            artifacts: $artifacts,
        );
    }

    public static function fromArray(array $data): static
    {
        $mode = Read::nullableString($data, 'mode');
        $database = Read::array($data, 'database');

        return new self(
            total: Read::int($data, 'total'),
            passed: Read::int($data, 'passed'),
            failed: Read::int($data, 'failed'),
            errors: Read::int($data, 'errors'),
            skipped: Read::int($data, 'skipped'),
            environmentError: Read::bool($data, 'environment_error'),
            path: Read::nullableString($data, 'path'),
            mode: $mode === 'suite' || $mode === 'path' ? $mode : null,
            artifacts: Read::int($data, 'artifacts'),
            database: $database === [] ? null : DatabaseGuardResult::fromArray($database),
        );
    }

    public function failures(): int
    {
        return $this->failed + $this->errors;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'skipped' => $this->skipped,
            'environment_error' => $this->environmentError,
            'path' => $this->path,
            'mode' => $this->mode,
            'artifacts' => $this->artifacts,
            'database' => $this->database?->toArray(),
            'file' => 'tests/browser-condensed.json',
        ];
    }
}
