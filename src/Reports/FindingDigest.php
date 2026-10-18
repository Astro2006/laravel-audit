<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Severity;

final class FindingDigest
{
    public const int MAX_ROWS = 25;

    /**
     * A compact table of the findings for a prompt: ID, severity,
     * confidence, check and title, most severe first, at most MAX_ROWS.
     *
     * @param  list<ParsedFinding>  $findings
     */
    public static function table(array $findings): string
    {
        if ($findings === []) {
            return 'No findings.';
        }

        $rank = fn (ParsedFinding $finding): array => [
            Severity::tryFrom(strtolower((string) $finding->field('severity')))?->rank() ?? 0,
            Confidence::tryFrom(strtolower((string) $finding->field('confidence')))?->rank() ?? 0,
        ];
        usort($findings, fn (ParsedFinding $a, ParsedFinding $b): int => $rank($b) <=> $rank($a));

        $rows = ['| ID | Severity | Confidence | Check | Title |', '|---|---|---|---|---|'];

        foreach (array_slice($findings, 0, self::MAX_ROWS) as $finding) {
            $rows[] = sprintf('| %s | %s | %s | `%s` | %s |', $finding->id, $finding->field('severity') ?? '?', $finding->field('confidence') ?? '?', $finding->field('check') ?? '?', str_replace('|', '\|', $finding->title));
        }

        if (count($findings) > self::MAX_ROWS) {
            $rows[] = sprintf('+%d more low or info findings.', count($findings) - self::MAX_ROWS);
        }

        return implode("\n", $rows);
    }
}
