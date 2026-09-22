<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

/** phpstan/phpstan-grouped.json: one row per error identifier, baselined ones marked. */
final class PhpstanSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::Phpstan;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'phpstan/phpstan-grouped.json');

        if ($report === null) {
            return null;
        }

        $groups = [
            ...array_map(fn (array $group): array => [...$group, 'baselined' => false], Read::arrays($report, 'identifiers')),
            ...array_map(fn (array $group): array => [...$group, 'baselined' => true], Read::arrays($report, 'baselined_identifiers')),
        ];

        $rows = array_map(fn (array $group): SnapshotRow => new SnapshotRow(
            [
                Read::string($group, 'identifier'),
                (string) Read::int($group, 'count'),
                (string) Read::int($group, 'file_count'),
                $group['baselined'] ? 'baselined' : 'open',
            ],
            Details::fields([
                'Identifier' => Read::string($group, 'identifier'),
                'Errors' => (string) Read::int($group, 'count'),
                'In baseline' => $group['baselined'],
                'Top files' => array_map(fn (array $file): string => sprintf('`%s` (%d)', Read::string($file, 'file'), Read::int($file, 'count')), Read::arrays($group, 'top_files')),
                'Examples' => array_map(fn (array $message): string => sprintf('`%s:%s` — %s', Read::string($message, 'file'), Read::nullableInt($message, 'line') ?? '?', Read::string($message, 'message')), Read::arrays($group, 'examples')),
            ]),
        ), $groups);

        $general = Read::strings($report, 'general_errors');

        return new SnapshotManifest(
            SnapshotType::Phpstan,
            sprintf('PHPStan (level %s) — %s', Read::string($report, 'level', '?'), Read::int($report, 'errors') > 0 ? '🔴 '.Read::int($report, 'errors').' errors' : '🟢 no errors'),
            [
                'Errors '.Read::int($report, 'errors'),
                'Baselined '.Read::int($report, 'baselined'),
                'Identifiers '.count($groups),
                'General errors '.count($general),
            ],
            ['Identifier', 'Errors', 'Files', 'State'],
            $rows,
            $general === [] ? [] : [new SnapshotFile('general', 'General errors (not tied to a file)', Details::list($general))],
        );
    }
}
