<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Run\RunManifest;

final class GeneratedSummary
{
    private const int MOST_IMPORTANT = 3;

    /**
     * Used when the summary task is off, failed or has not run yet: counts,
     * the findings to look at first, the security findings, and what did
     * not run.
     *
     * @param  list<Finding>  $findings  with their final IDs
     * @param  list<string>  $completed
     * @param  list<string>  $failed
     * @param  list<string>  $skipped
     */
    public static function write(array $findings, array $completed, array $failed, array $skipped = []): string
    {
        $counts = RunManifest::severityCounts($findings);
        $parts = [];

        foreach ($counts as $severity => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.$severity;
            }
        }

        $sentences = [sprintf(
            '%d findings from %d tasks%s.',
            count($findings),
            count($completed),
            $parts === [] ? '' : ' ('.implode(', ', $parts).')',
        )];

        $important = array_filter($findings, fn (Finding $finding): bool => $finding->severity->rank() >= Severity::Medium->rank());
        usort($important, fn (Finding $a, Finding $b): int => [$b->severity->rank(), $b->confidence->rank()] <=> [$a->severity->rank(), $a->confidence->rank()]);

        if ($important !== []) {
            $sentences[] = 'Most important: '.implode('; ', array_map(fn (Finding $finding): string => $finding->id.' — '.$finding->title, array_slice($important, 0, self::MOST_IMPORTANT))).'.';
        }

        $security = array_filter($findings, fn (Finding $finding): bool => $finding->category === Category::Security);

        if ($security !== []) {
            $sentences[] = 'Security: '.implode(', ', array_map(fn (Finding $finding): string => $finding->id, $security)).'.';
        }

        if ($failed !== []) {
            $sentences[] = sprintf('%d tasks failed (%s); see Limitations.', count($failed), implode(', ', $failed));
        }

        if ($skipped !== []) {
            $sentences[] = sprintf('%d tasks had nothing to audit and were skipped (%s).', count($skipped), implode(', ', $skipped));
        }

        return implode(' ', $sentences);
    }
}
