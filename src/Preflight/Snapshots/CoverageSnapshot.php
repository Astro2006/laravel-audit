<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class CoverageSnapshot extends PreflightSnapshot
{
    private const string NOT_A_PATH = '/\\\\(Enums|Exceptions)\\\\|Exception$/';

    public function type(): SnapshotType
    {
        return SnapshotType::Coverage;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'tests/coverage-by-class.json');

        if ($report === null) {
            return null;
        }

        $all = Read::arrays($report, 'classes');
        $classes = array_values(array_filter($all, fn (array $class): bool => preg_match(self::NOT_A_PATH, Read::string($class, 'class')) !== 1));
        usort($classes, fn (array $a, array $b): int => Read::float($a, 'lines_pct') <=> Read::float($b, 'lines_pct'));
        $percent = Read::nullableFloat($report, 'lines_pct');

        return new SnapshotManifest(
            SnapshotType::Coverage,
            'Coverage — '.($percent === null ? 'unknown' : sprintf('%.1f %% lines', $percent)),
            [
                'Classes '.count($all).' ('.(count($all) - count($classes)).' enums and exceptions not listed)',
                'Below 50 % '.count(array_filter($classes, fn (array $class): bool => Read::float($class, 'lines_pct') < 50)),
                'Uncovered (0 %) '.count(array_filter($classes, fn (array $class): bool => Read::float($class, 'lines_pct') <= 0)),
            ],
            ['Class', 'Lines %', 'Uncovered methods'],
            array_map(fn (array $class): SnapshotRow => new SnapshotRow(
                [
                    Read::nullableString($class, 'class') ?? Read::string($class, 'file'),
                    sprintf('%.1f', Read::float($class, 'lines_pct')),
                    (string) count(Read::strings($class, 'uncovered_methods')),
                ],
                Details::fields([
                    'Class' => Read::nullableString($class, 'class'),
                    'File' => '`'.Read::string($class, 'file').'`',
                    'Lines covered' => sprintf('%.1f %%', Read::float($class, 'lines_pct')),
                    'Statements' => (string) Read::int($class, 'statements'),
                    'Uncovered methods' => Read::strings($class, 'uncovered_methods'),
                ]),
            ), $classes),
        );
    }
}
