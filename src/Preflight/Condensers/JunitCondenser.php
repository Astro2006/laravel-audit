<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use SimpleXMLElement;

/**
 * Turns a JUnit report (PHPUnit or Pest) into what an agent needs:
 * totals, failures grouped by cause, skipped tests, slowest tests.
 */
final class JunitCondenser
{
    private const int MAX_GROUPS = 20;

    private const int MAX_SKIPPED = 30;

    /**
     * @return array{total: int, passed: int, failed: int, errors: int, skipped: int, duration_s: float, environment_error: bool, failure_groups: list<array<string, mixed>>, skipped_tests: list<array<string, string>>, slowest: list<array<string, mixed>>}|null
     */
    public function condense(string $junitPath, string $basePath): ?array
    {
        if (! is_file($junitPath)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($junitPath);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            return null;
        }

        $counts = ['total' => 0, 'passed' => 0, 'failed' => 0, 'errors' => 0, 'skipped' => 0];
        $groups = [];
        $skipped = [];
        $durations = [];
        $duration = 0.0;

        foreach ($xml->xpath('//testcase') ?: [] as $case) {
            $counts['total']++;
            $name = $this->testName($case);
            $file = $this->relativeFile((string) ($case['file'] ?? ''), $basePath);
            $time = (float) ($case['time'] ?? 0);
            $duration += $time;
            $durations[] = ['test' => $name, 'seconds' => round($time, 3)];

            $problem = $case->failure[0] ?? $case->error[0] ?? null;

            if ($problem !== null) {
                $isError = isset($case->error[0]) && ! isset($case->failure[0]);
                $counts[$isError ? 'errors' : 'failed']++;

                $type = (string) ($problem['type'] ?? ($isError ? 'Error' : 'Failure'));
                $message = $this->firstLine((string) ($problem['message'] ?? '') ?: (string) $problem);
                $key = $type.'|'.$message;

                $groups[$key] ??= ['exception' => $type, 'message' => $message, 'count' => 0, 'tests' => [], 'files' => []];
                $groups[$key]['count']++;

                if (count($groups[$key]['tests']) < 5) {
                    $groups[$key]['tests'][] = $name;
                }

                if ($file !== '' && ! in_array($file, $groups[$key]['files'], true)) {
                    $groups[$key]['files'][] = $file;
                }

                continue;
            }

            if (isset($case->skipped[0])) {
                $counts['skipped']++;

                if (count($skipped) < self::MAX_SKIPPED) {
                    $skipped[] = [
                        'test' => $name,
                        'reason' => $this->firstLine((string) ($case->skipped[0]['message'] ?? '') ?: (string) $case->skipped[0]),
                        'file' => $file,
                    ];
                }

                continue;
            }

            $counts['passed']++;
        }

        usort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        usort($durations, fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        $broken = $counts['failed'] + $counts['errors'];

        return [
            ...$counts,
            'duration_s' => round($duration, 2),
            'environment_error' => $this->looksLikeBrokenEnvironment($counts['total'], $broken, $groups[0]['count'] ?? 0),
            'failure_groups' => array_slice($groups, 0, self::MAX_GROUPS),
            'skipped_tests' => $skipped,
            'slowest' => array_slice($durations, 0, 10),
        ];
    }

    /**
     * More than 90 % red with one shared cause: a missing APP_KEY, a dead
     * database connection or a browser that does not start — not the code.
     */
    private function looksLikeBrokenEnvironment(int $total, int $broken, int $largestGroup): bool
    {
        return $total >= 5
            && $broken >= 0.9 * $total
            && $largestGroup >= 0.9 * $broken;
    }

    private function testName(SimpleXMLElement $case): string
    {
        $class = (string) ($case['class'] ?? '');

        if ($class === '' && isset($case['classname'])) {
            $class = str_replace('.', '\\', (string) $case['classname']);
        }

        $name = (string) ($case['name'] ?? '');

        return $class === '' ? $name : $class.'::'.$name;
    }

    private function relativeFile(string $file, string $basePath): string
    {
        $file = str_replace('\\', '/', (string) preg_replace('/::.*$/', '', $file));
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private function firstLine(string $text): string
    {
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return mb_substr($line, 0, 300);
            }
        }

        return '';
    }
}
