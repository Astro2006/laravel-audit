<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class RouteTestsSnapshot extends PreflightSnapshot
{
    private const string PACKAGE_URIS = '~^/?(_boost|_ignition|_debugbar|livewire(-\w+)?|sanctum|telescope|horizon|pulse|up$|storage/)~';

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

        $all = Read::arrays($report, 'routes');
        $routes = array_values(array_filter($all, fn (array $route): bool => ! self::fromPackage($route)));
        $untested = array_values(array_filter($routes, fn (array $route): bool => Read::strings($route, 'tests') === []));
        $tested = array_values(array_filter($routes, fn (array $route): bool => Read::strings($route, 'tests') !== []));

        return new SnapshotManifest(
            SnapshotType::RouteTests,
            sprintf('Routes and tests (heuristic) — %d of %d routes without a test', count($untested), count($routes)),
            ['Routes '.count($routes), 'With a test '.count($tested), 'Without '.count($untested), 'Package routes '.(count($all) - count($routes)).' (not listed)'],
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

    /** @param array<mixed> $route */
    private static function fromPackage(array $route): bool
    {
        $action = Read::string($route, 'action');
        $isAppCode = str_starts_with($action, 'App\\') || $action === 'Closure';

        return ! $isAppCode || preg_match(self::PACKAGE_URIS, ltrim(Read::string($route, 'uri'), '/')) === 1;
    }
}
