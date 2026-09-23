<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Tests\FailureGroup;
use Astro2006\LaravelAudit\Preflight\Data\Tests\JunitReport;
use Astro2006\LaravelAudit\Preflight\Data\Tests\SkippedTest;
use Astro2006\LaravelAudit\Preflight\Data\Tests\SlowTest;
use Astro2006\LaravelAudit\Preflight\Data\Tests\TestFailure;
use Astro2006\LaravelAudit\Support\Regex;
use Astro2006\LaravelAudit\Support\Xml;
use Illuminate\Support\Collection;
use SimpleXMLElement;

final class JunitCondenser
{
    private const int MAX_GROUPS = 20;

    private const int MAX_SKIPPED = 30;

    public function condense(string $junitPath, string $basePath): ?JunitReport
    {
        $xml = Xml::load($junitPath);

        if ($xml === null) {
            return null;
        }

        $total = 0;
        $passed = 0;
        $failures = [];
        $skipped = [];
        $durations = [];
        $errors = 0;
        $skippedCount = 0;

        foreach (Xml::xpath($xml, '//testcase') as $case) {
            $total++;
            $name = $this->testName($case);
            $file = $this->relativeFile(Xml::attr($case, 'file'), $basePath);
            $time = (float) Xml::attr($case, 'time', '0');
            $durations[] = new SlowTest($name, round($time, 3));

            $failure = Xml::child($case, 'failure');
            $error = Xml::child($case, 'error');
            $problem = $failure ?? $error;

            if ($problem !== null) {
                $isError = $failure === null;
                $errors += $isError ? 1 : 0;
                $identifier = Xml::attr($case, 'class') !== '' ? Xml::attr($case, 'class').'::'.Xml::attr($case, 'name') : null;
                $message = $this->firstLine(Xml::attr($problem, 'message') ?: (string) $problem, $identifier);
                $failures[] = new TestFailure(Xml::attr($problem, 'type', $isError ? 'Error' : 'Failure'), $message, $name, $file);

                continue;
            }

            $skip = Xml::child($case, 'skipped');

            if ($skip !== null) {
                $skippedCount++;

                if (count($skipped) < self::MAX_SKIPPED) {
                    $skipped[] = new SkippedTest($name, $this->firstLine(Xml::attr($skip, 'message') ?: (string) $skip), $file);
                }

                continue;
            }

            $passed++;
        }

        $groups = $this->group($failures);
        $failed = count($failures) - $errors;

        usort($durations, fn (SlowTest $a, SlowTest $b): int => $b->seconds <=> $a->seconds);

        return new JunitReport(
            total: $total,
            passed: $passed,
            failed: $failed,
            errors: $errors,
            skipped: $skippedCount,
            durationSeconds: round(array_sum(array_map(fn (SlowTest $test): float => $test->seconds, $durations)), 2),
            environmentError: $this->looksLikeBrokenEnvironment($total, count($failures), max([0, ...array_map(fn (FailureGroup $group): int => $group->count, $groups)])),
            failureGroups: array_slice($groups, 0, self::MAX_GROUPS),
            skippedTests: $skipped,
            slowest: array_slice($durations, 0, 10),
        );
    }

    /**
     * @param  list<TestFailure>  $failures
     * @return list<FailureGroup> largest first
     */
    private function group(array $failures): array
    {
        return (new Collection($failures))
            ->groupBy(fn (TestFailure $failure): string => $failure->groupKey())
            ->map(function (Collection $group): FailureGroup {
                /** @var TestFailure $first */
                $first = $group->first();

                return new FailureGroup(
                    $first->type,
                    $first->message,
                    $group->count(),
                    $group->take(5)->map(fn (TestFailure $failure): string => $failure->test)->values()->all(),
                    $group->map(fn (TestFailure $failure): string => $failure->file)->filter()->unique()->values()->all(),
                );
            })
            ->sortByDesc(fn (FailureGroup $group): int => $group->count)
            ->values()
            ->all();
    }

    private function looksLikeBrokenEnvironment(int $total, int $broken, int $largestGroup): bool
    {
        return $total >= 5
            && $broken >= 0.9 * $total
            && $largestGroup >= 0.9 * $broken;
    }

    private function testName(SimpleXMLElement $case): string
    {
        $class = Xml::attr($case, 'class') ?: str_replace('.', '\\', Xml::attr($case, 'classname'));
        $name = Xml::attr($case, 'name');

        return $class === '' ? $name : $class.'::'.$name;
    }

    private function relativeFile(string $file, string $basePath): string
    {
        $file = str_replace('\\', '/', (string) preg_replace('/::.*$/', '', $file));
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private function firstLine(string $text, ?string $skipPrefix = null): string
    {
        foreach (Regex::lines(trim($text)) as $line) {
            $line = trim($line);

            if ($skipPrefix !== null && str_starts_with($line, $skipPrefix)) {
                continue;
            }

            if ($line !== '') {
                return mb_substr($line, 0, 300);
            }
        }

        return '';
    }
}
