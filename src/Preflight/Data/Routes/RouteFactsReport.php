<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Routes;

use Illuminate\Contracts\Support\Arrayable;

/**
 * route-facts.json: candidates for the frontend agent, not findings.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RouteFactsReport implements Arrayable
{
    /**
     * @param  list<RouteDefinition>  $unauthenticatedWrite
     * @param  list<RouteDefinition>  $stateChangingGet
     * @param  list<RouteDefinition>  $missingThrottle
     * @param  list<RouteDefinition>  $debugRoutes
     * @param  list<RouteDefinition>  $brokenRoutes
     */
    public function __construct(
        public int $total,
        public array $unauthenticatedWrite,
        public array $stateChangingGet,
        public array $missingThrottle,
        public array $debugRoutes,
        public array $brokenRoutes,
    ) {}

    public function flagged(): int
    {
        return count($this->unauthenticatedWrite) + count($this->stateChangingGet) + count($this->missingThrottle)
            + count($this->debugRoutes) + count($this->brokenRoutes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $list = fn (array $routes): array => array_map(fn (RouteDefinition $route): array => $route->toArray(), $routes);

        return [
            'total' => $this->total,
            'flagged' => $this->flagged(),
            'unauthenticated_write' => $list($this->unauthenticatedWrite),
            'state_changing_get' => $list($this->stateChangingGet),
            'missing_throttle' => $list($this->missingThrottle),
            'debug_routes' => $list($this->debugRoutes),
            'broken_routes' => $list($this->brokenRoutes),
        ];
    }
}
