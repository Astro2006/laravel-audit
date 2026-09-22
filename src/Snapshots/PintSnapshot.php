<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class PintSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::Pint;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'pint-grouped.json');

        if ($report === null) {
            return null;
        }

        $rules = Read::arrays($report, 'rules');

        return new SnapshotManifest(
            SnapshotType::Pint,
            'Pint — '.($rules === [] ? '🟢 clean' : sprintf('%d files with issues', Read::int($report, 'files_with_issues'))),
            ['Files with issues '.Read::int($report, 'files_with_issues'), 'Rules '.count($rules)],
            ['Rule', 'Files'],
            array_map(fn (array $rule): SnapshotRow => new SnapshotRow(
                [Read::string($rule, 'rule'), (string) Read::int($rule, 'files')],
                Details::fields([
                    'Rule' => Read::string($rule, 'rule'),
                    'Files' => (string) Read::int($rule, 'files'),
                    'Example files' => array_map(fn (string $file): string => '`'.$file.'`', Read::strings($rule, 'examples')),
                ]),
            ), $rules),
        );
    }
}
