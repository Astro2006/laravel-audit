<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Illuminate\Contracts\Support\Arrayable;

/**
 * route-test-map.json.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RouteTestMap implements Arrayable
{
    /** @param list<RouteTestLink> $routes */
    public function __construct(public array $routes) {}

    public function untested(): int
    {
        return count(array_filter($this->routes, fn (RouteTestLink $route): bool => $route->tests === []));
    }

    public function forUri(string $uri): ?RouteTestLink
    {
        foreach ($this->routes as $route) {
            if ($route->uri === $uri) {
                return $route;
            }
        }

        return null;
    }

    /** @return array{total: int, untested: int, routes: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'total' => count($this->routes),
            'untested' => $this->untested(),
            'routes' => array_map(fn (RouteTestLink $route): array => $route->toArray(), $this->routes),
        ];
    }
}
