<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Preflight\Data\Tests\JunitReport;
use Astro2006\LaravelAudit\Preflight\DatabaseGuardResult;
use Astro2006\LaravelAudit\Support\Read;

final readonly class TestsDTO implements StepDTO
{
    /**
     * @param  list<string>  $failing  first failing test names
     * @param  list<string>  $suites  suites that ran (empty = the default set)
     */
    public function __construct(
        public int $total = 0,
        public int $passed = 0,
        public int $failed = 0,
        public int $errors = 0,
        public int $skipped = 0,
        public float $durationSeconds = 0.0,
        public bool $environmentError = false,
        public ?string $topFailure = null,
        public array $failing = [],
        public bool $coverageAvailable = false,
        public ?float $coveragePct = null,
        public bool $parallel = false,
        public array $suites = [],
        public ?DatabaseGuardResult $database = null,
    ) {}

    /** @param list<string> $suites */
    public static function fromReport(JunitReport $report, ?float $coveragePct, bool $parallel, array $suites, ?DatabaseGuardResult $database): self
    {
        return new self(
            total: $report->total,
            passed: $report->passed,
            failed: $report->failed,
            errors: $report->errors,
            skipped: $report->skipped,
            durationSeconds: $report->durationSeconds,
            environmentError: $report->environmentError,
            topFailure: $report->topFailure(),
            failing: $report->failingTests(),
            coverageAvailable: $coveragePct !== null,
            coveragePct: $coveragePct,
            parallel: $parallel,
            suites: $suites,
            database: $database,
        );
    }

    public static function fromArray(array $data): static
    {
        $database = Read::array($data, 'database');

        return new self(
            total: Read::int($data, 'total'),
            passed: Read::int($data, 'passed'),
            failed: Read::int($data, 'failed'),
            errors: Read::int($data, 'errors'),
            skipped: Read::int($data, 'skipped'),
            durationSeconds: Read::float($data, 'suite_duration_s'),
            environmentError: Read::bool($data, 'environment_error'),
            topFailure: Read::nullableString($data, 'top_failure'),
            failing: Read::strings($data, 'failing'),
            coverageAvailable: Read::bool($data, 'coverage_available'),
            coveragePct: Read::nullableFloat($data, 'coverage_pct'),
            parallel: Read::bool($data, 'parallel'),
            suites: Read::strings($data, 'suites'),
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
            'suite_duration_s' => $this->durationSeconds,
            'environment_error' => $this->environmentError,
            'top_failure' => $this->topFailure,
            'failing' => $this->failing,
            'coverage_available' => $this->coverageAvailable,
            'coverage_pct' => $this->coveragePct,
            'parallel' => $this->parallel,
            'suites' => $this->suites,
            'database' => $this->database?->toArray(),
            'file' => 'tests/tests-condensed.json',
        ];
    }
}
