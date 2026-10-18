<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class CodeCandidatesSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::CodeCandidates;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $data = $this->read($preflight, 'code/candidates.json');

        if ($data === null) {
            return null;
        }

        $patterns = array_values(array_filter(Read::arrays($data, 'patterns'), fn (array $pattern): bool => Read::int($pattern, 'total') > 0));

        return new SnapshotManifest(
            SnapshotType::CodeCandidates,
            'Code candidates — lines to check, found by pattern (not findings)',
            $patterns === [] ? ['No pattern matched.'] : ['Open the detail file of the rows for your checks instead of searching the codebase'],
            ['Pattern', 'Checks', 'Lines', 'Files'],
            array_map(fn (array $pattern): SnapshotRow => $this->row($pattern), $patterns),
        );
    }

    /** @param array<mixed> $pattern */
    private function row(array $pattern): SnapshotRow
    {
        $hits = Read::arrays($pattern, 'hits');
        $files = array_values(array_unique(array_map(fn (array $hit): string => Read::string($hit, 'file'), $hits)));
        $total = Read::int($pattern, 'total');

        return new SnapshotRow(
            [
                Read::string($pattern, 'pattern'),
                implode(', ', array_map(fn (string $check): string => '`'.$check.'`', Read::strings($pattern, 'checks'))),
                (string) $total,
                self::few($files),
            ],
            Details::list([
                ...array_map(fn (array $hit): string => sprintf('`%s:%d` — `%s`', Read::string($hit, 'file'), Read::int($hit, 'line'), str_replace('`', "'", Read::string($hit, 'code'))), $hits),
                ...($total > count($hits) ? [sprintf('… %d more lines not listed', $total - count($hits))] : []),
            ]),
        );
    }
}
