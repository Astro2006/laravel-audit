<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Support\Read;

abstract class JunitSnapshot extends PreflightSnapshot
{
    /**
     * @param  array<mixed>  $report  tests-condensed.json or browser-condensed.json
     * @param  list<SnapshotFile>  $files
     */
    protected function manifest(string $label, array $report, array $files = []): SnapshotManifest
    {
        $failed = Read::int($report, 'failed') + Read::int($report, 'errors');
        $status = $failed > 0 ? '🔴 FAILED' : '🟢 PASSED';

        $rows = array_map(fn (array $group): SnapshotRow => new SnapshotRow(
            [
                (string) Read::int($group, 'count'),
                self::shortClass(Read::string($group, 'exception')),
                self::few(Read::strings($group, 'files')),
            ],
            implode("\n\n", array_filter([
                Details::fields([
                    'Exception' => Read::string($group, 'exception'),
                    'Failing tests' => (string) Read::int($group, 'count'),
                    'Tests' => Read::strings($group, 'tests'),
                    'Files' => Read::strings($group, 'files'),
                ]),
                "**Message**\n\n".Details::text(Read::string($group, 'message')),
            ])),
        ), Read::arrays($report, 'failure_groups'));

        $skipped = Read::arrays($report, 'skipped_tests');

        if ($skipped !== []) {
            $files[] = new SnapshotFile('skipped', 'Skipped tests', Details::list(array_map(
                fn (array $test): string => sprintf('`%s` (%s) — %s', Read::string($test, 'test'), Read::string($test, 'file'), Read::string($test, 'reason') ?: 'no reason'),
                $skipped,
            )));
        }

        $slowest = Read::arrays($report, 'slowest');

        if ($slowest !== []) {
            $files[] = new SnapshotFile('slow', 'Slowest tests', Details::list(array_map(
                fn (array $test): string => sprintf('`%s` — %.2f s', Read::string($test, 'test'), Read::float($test, 'seconds')),
                $slowest,
            )));
        }

        return new SnapshotManifest(
            $this->type(),
            $label.' — '.$status.(Read::bool($report, 'environment_error') ? ' (environment error: results unreliable)' : ''),
            [
                'Total '.Read::int($report, 'total'),
                'Passed '.Read::int($report, 'passed'),
                'Failed '.$failed,
                'Skipped '.Read::int($report, 'skipped'),
                sprintf('%.1f s', Read::float($report, 'duration_s')),
            ],
            ['Tests', 'Exception', 'Files'],
            $rows,
            $files,
        );
    }
}
