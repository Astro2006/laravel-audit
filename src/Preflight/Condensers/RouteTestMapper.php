<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Routes\RouteDefinition;
use Astro2006\LaravelAudit\Data\Tests\RouteTestLink;
use Astro2006\LaravelAudit\Data\Tests\RouteTestMap;

final class RouteTestMapper
{
    /**
     * @param  list<RouteDefinition>  $routes
     * @param  iterable<string, string>  $testFiles  relative path => contents
     */
    public function map(array $routes, iterable $testFiles): RouteTestMap
    {
        $files = [];

        foreach ($testFiles as $path => $contents) {
            $files[] = [$path, $contents];
        }

        $links = [];

        foreach ($routes as $route) {
            $tests = [];
            $matchedBy = [];

            foreach ($files as [$path, $contents]) {
                foreach ($this->needles($route) as [$kind, $pattern]) {
                    if (str_contains($contents, $pattern)) {
                        $tests[] = $path;
                        $matchedBy[] = $kind;

                        break;
                    }
                }
            }

            $links[] = new RouteTestLink(
                method: $route->method,
                uri: $route->uri,
                name: $route->name,
                action: $route->action,
                tests: array_slice(array_values(array_unique($tests)), 0, 10),
                matchedBy: array_values(array_unique($matchedBy)),
            );
        }

        return new RouteTestMap($links);
    }

    /** @return list<array{string, string}> kind and pattern */
    private function needles(RouteDefinition $route): array
    {
        $needles = [];
        $uri = trim($route->uri, '/');

        if ($route->name !== null) {
            $needles[] = ['name', "route('{$route->name}'"];
            $needles[] = ['name', "route(\"{$route->name}\""];
        }

        $static = strstr($uri, '{', true);
        $static = $static === false ? $uri : rtrim($static, '/').'/';

        if (strlen($static) >= 3) {
            $patterns = $static === $uri
                ? ["'/{$uri}'", "\"/{$uri}\"", "'/{$uri}?", "\"/{$uri}?"]
                : ["'/{$static}", "\"/{$static}"];

            foreach ($patterns as $pattern) {
                $needles[] = ['uri', $pattern];
            }
        }

        if ($route->isControllerAction()) {
            $class = str_contains($route->action, '@') ? strstr($route->action, '@', true) : $route->action;
            $short = substr((string) $class, (int) strrpos((string) $class, '\\') + 1);
            $needles[] = ['controller', $short.'::class'];
            $needles[] = ['controller', (string) $class];
        }

        return $needles;
    }
}
