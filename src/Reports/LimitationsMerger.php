<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Arr;

final class LimitationsMerger
{
    public const int PER_TASK = 3;

    public const int MAX_OUT_OF_SCOPE = 5;

    private const string PREFLIGHT_FINE = '/preflight.*\b(ok|succeeded|successful|finished|ran|completed|fine)\b|\bno (preflight )?steps? (was|were) skipped/i';

    private const string OUT_OF_SCOPE = '/^###\s+Out of scope observations/i';

    /**
     * A task's Limitations section as bullets, and the out-of-scope
     * observations below their own heading.
     *
     * @return array{list<string>, list<string>}
     */
    public static function split(string $section): array
    {
        $limitations = [];
        $outOfScope = [];
        $scope = false;

        foreach (Regex::lines($section) as $line) {
            if (preg_match(self::OUT_OF_SCOPE, $line) === 1) {
                $scope = true;

                continue;
            }

            $bullet = self::bullet($line);

            if ($bullet === null) {
                continue;
            }

            if ($scope) {
                $outOfScope[] = $bullet;
            } else {
                $limitations[] = $bullet;
            }
        }

        return [$limitations, $outOfScope];
    }

    /**
     * The limitations of all tasks: without the ones that only say the
     * preflight went fine, without repeats, at most PER_TASK per task,
     * each prefixed with its task's title.
     *
     * @param  array<string, array{title: string, bullets: list<string>}>  $byTask
     * @return list<string>
     */
    public static function merge(array $byTask): array
    {
        $seen = [];
        $merged = [];

        foreach ($byTask as $task) {
            $title = (string) Arr::get($task, 'title', '');
            $kept = [];

            foreach (Arr::get($task, 'bullets', []) as $bullet) {
                $text = self::text($bullet);
                $normalized = self::normalize($text);

                if ($normalized === '' || preg_match(self::PREFLIGHT_FINE, $text) === 1 || in_array($normalized, $seen, true)) {
                    continue;
                }

                $seen[] = $normalized;
                $kept[] = $text;
            }

            foreach (array_slice($kept, 0, self::PER_TASK) as $text) {
                $merged[] = sprintf('- **%s:** %s', $title, $text);
            }

            if (count($kept) > self::PER_TASK) {
                $more = count($kept) - self::PER_TASK;
                $merged[] = sprintf('- **%s:** %d more %s in the task log.', $title, $more, $more === 1 ? 'limitation' : 'limitations');
            }
        }

        return $merged;
    }

    /**
     * Out-of-scope observations without repeats, without the ones about a
     * location an existing finding already covers, at most MAX_OUT_OF_SCOPE.
     *
     * @param  list<string>  $bullets
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    public static function outOfScope(array $bullets, array $findings): array
    {
        $targets = [];

        foreach ($findings as $finding) {
            foreach ($finding->locations as $location) {
                array_push($targets, strtolower($location->target()), strtolower((string) $location->file), strtolower((string) $location->route), strtolower((string) $location->package));
            }
        }

        $seen = [];
        $kept = [];

        foreach ($bullets as $bullet) {
            $normalized = self::normalize(self::text($bullet));
            $mentions = array_map(fn (string $mention): string => strtolower(trim((string) preg_replace('/:\d+(-\d+)?$/', '', $mention))), Regex::all('/`([^`]+)`/', $bullet));

            if ($normalized === '' || in_array($normalized, $seen, true) || array_intersect($mentions, $targets) !== []) {
                continue;
            }

            $seen[] = $normalized;
            $kept[] = $bullet;

            if (count($kept) === self::MAX_OUT_OF_SCOPE) {
                break;
            }
        }

        return $kept;
    }

    private static function bullet(string $line): ?string
    {
        $line = rtrim($line);
        $text = trim($line);

        if ($text === '' || preg_match('/^(none|n\/a|keine)\.?$/i', $text) === 1) {
            return null;
        }

        return preg_match('/^\s*([-*|]|\d+\.)\s*/', $line) === 1 ? $line : '- '.$text;
    }

    private static function text(string $bullet): string
    {
        return trim((string) preg_replace('/^\s*([-*]|\d+\.)\s*/', '', $bullet));
    }

    private static function normalize(string $text): string
    {
        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $text)));
    }
}
