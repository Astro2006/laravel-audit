<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class RoutesSnapshot extends PreflightSnapshot
{
    private const array FACTS = [
        'unauthenticated_write' => 'write without auth',
        'state_changing_get' => 'state-changing GET',
        'missing_throttle' => 'no throttle',
        'debug_routes' => 'debug route',
        'broken_routes' => 'broken route',
    ];

    public function type(): SnapshotType
    {
        return SnapshotType::Routes;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $routes = $this->read($preflight, 'routes.json');

        if ($routes === null) {
            return null;
        }

        $routes = array_values(array_filter($routes, 'is_array'));
        $facts = $this->read($preflight, 'route-facts.json') ?? [];
        $rows = [];

        foreach (self::FACTS as $key => $label) {
            foreach (Read::arrays($facts, $key) as $route) {
                $rows[] = new SnapshotRow(
                    [$label, $this->route($route), Read::string($route, 'action')],
                    Details::fields([
                        'Candidate' => $label.' (not yet a finding: confirm or reject it in the code)',
                        'Route' => '`'.$this->route($route).'`',
                        'Name' => Read::nullableString($route, 'name'),
                        'Action' => Read::string($route, 'action'),
                        'Middleware' => Read::strings($route, 'middleware'),
                    ]),
                );
            }
        }

        $methods = [];

        foreach ($routes as $route) {
            foreach (explode('|', Read::string($route, 'method')) as $method) {
                if ($method !== 'HEAD' && $method !== '') {
                    $methods[$method] = Arr::get($methods, $method, 0) + 1;
                }
            }
        }

        ksort($methods);

        return new SnapshotManifest(
            SnapshotType::Routes,
            sprintf('Routes — %d routes · %d flagged candidates', count($routes), count($rows)),
            array_map(fn (string $method, int $count): string => $method.' '.$count, array_keys($methods), $methods),
            ['Candidate', 'Route', 'Action'],
            $rows,
            [new SnapshotFile('all-routes', 'All routes (method, URI, action, middleware)', implode("\n", [
                '| Route | Name | Action | Middleware |',
                '|---|---|---|---|',
                ...array_map(fn (array $route): string => sprintf(
                    '| `%s` | %s | %s | %s |',
                    $this->route($route),
                    Read::nullableString($route, 'name') ?? '',
                    str_replace('|', '\\|', Read::string($route, 'action')),
                    implode(', ', Read::strings($route, 'middleware')),
                ), $routes),
            ]))],
        );
    }

    /** @param  array<mixed>  $route */
    private function route(array $route): string
    {
        return str_replace('|HEAD', '', Read::string($route, 'method')).' '.Read::string($route, 'uri');
    }
}
