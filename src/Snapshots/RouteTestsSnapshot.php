<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class RouteTestsSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::RouteTests;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'tests/route-test-map.json');

        if ($report === null) {
            return null;
        }

        $routes = Read::arrays($report, 'routes');
        $untested = array_values(array_filter($routes, fn (array $route): bool => Read::strings($route, 'tests') === []));
        $tested = array_values(array_filter($routes, fn (array $route): bool => Read::strings($route, 'tests') !== []));

        return new SnapshotManifest(
            SnapshotType::RouteTests,
            sprintf('Routes and tests (heuristic) — %d of %d routes without a test', count($untested), count($routes)),
            ['Routes '.count($routes), 'With a test '.count($tested), 'Without '.count($untested)],
            ['Route', 'Action'],
            array_map(fn (array $route): SnapshotRow => new SnapshotRow([
                Read::string($route, 'method').' '.Read::string($route, 'uri'),
                Read::string($route, 'action'),
            ]), $untested),
            $tested === [] ? [] : [new SnapshotFile('tested', 'Tested routes and their test files', Details::list(array_map(
                fn (array $route): string => sprintf('`%s %s` — %s (matched by %s)', Read::string($route, 'method'), Read::string($route, 'uri'), implode(', ', Read::strings($route, 'tests')), implode(', ', Read::strings($route, 'matched_by'))),
                $tested,
            )))],
        );
    }
}
