<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A condensed JUnit report: tests-condensed.json / browser-condensed.json.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class JunitReport implements Arrayable
{
    /**
     * @param  list<FailureGroup>  $failureGroups  largest group first
     * @param  list<SkippedTest>  $skippedTests
     * @param  list<SlowTest>  $slowest
     */
    public function __construct(
        public int $total,
        public int $passed,
        public int $failed,
        public int $errors,
        public int $skipped,
        public float $durationSeconds,
        public bool $environmentError,
        public array $failureGroups,
        public array $skippedTests,
        public array $slowest,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            total: Read::int($data, 'total'),
            passed: Read::int($data, 'passed'),
            failed: Read::int($data, 'failed'),
            errors: Read::int($data, 'errors'),
            skipped: Read::int($data, 'skipped'),
            durationSeconds: Read::float($data, 'duration_s'),
            environmentError: Read::bool($data, 'environment_error'),
            failureGroups: array_map(FailureGroup::fromArray(...), Read::arrays($data, 'failure_groups')),
            skippedTests: array_map(SkippedTest::fromArray(...), Read::arrays($data, 'skipped_tests')),
            slowest: array_map(SlowTest::fromArray(...), Read::arrays($data, 'slowest')),
        );
    }

    public function failures(): int
    {
        return $this->failed + $this->errors;
    }

    public function topFailure(): ?string
    {
        foreach ($this->failureGroups as $group) {
            return $group->label();
        }

        return null;
    }

    /** @return list<string> test names from the largest failure groups */
    public function failingTests(int $limit = 5): array
    {
        $names = [];

        foreach ($this->failureGroups as $group) {
            array_push($names, ...$group->tests);
        }

        return array_slice($names, 0, $limit);
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
            'duration_s' => $this->durationSeconds,
            'environment_error' => $this->environmentError,
            'failure_groups' => array_map(fn (FailureGroup $group): array => $group->toArray(), $this->failureGroups),
            'skipped_tests' => array_map(fn (SkippedTest $test): array => $test->toArray(), $this->skippedTests),
            'slowest' => array_map(fn (SlowTest $test): array => $test->toArray(), $this->slowest),
        ];
    }
}
