<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Run\RunManifest;

/**
 * The audit summary the harness writes itself, without an agent call.
 */
final class GeneratedSummary
{
    /**
     * Used when the summary task is off, failed or has not run yet: counts,
     * the most severe findings, and what did not run.
     *
     * @param  list<Finding>  $findings
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

        $top = array_filter($findings, fn (Finding $finding): bool => $finding->severity->rank() >= Severity::High->rank());
        usort($top, fn (Finding $a, Finding $b): int => $b->severity->rank() <=> $a->severity->rank());

        if ($top !== []) {
            $sentences[] = 'Most severe: '.implode('; ', array_map(fn (Finding $finding): string => $finding->title, array_slice($top, 0, 3))).'.';
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
