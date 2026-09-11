<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

/**
 * Heuristic map from routes to the test files that reference them by route
 * name, URI literal or controller class. The tests agent verifies it.
 */
final class RouteTestMapper
{
    /**
     * @param  list<array<string, mixed>>  $routes  decoded route:list --json
     * @param  array<string, string>  $testFiles  relative path => contents
     * @return array{total: int, untested: int, routes: list<array<string, mixed>>}
     */
    public function map(array $routes, array $testFiles): array
    {
        $result = [];
        $untested = 0;

        foreach ($routes as $route) {
            $uri = trim((string) ($route['uri'] ?? ''), '/');
            $name = (string) ($route['name'] ?? '');
            $action = (string) ($route['action'] ?? '');
            $needles = $this->needles($uri, $name, $action);

            $tests = [];
            $matchedBy = [];

            foreach ($testFiles as $file => $contents) {
                foreach ($needles as $kind => $patterns) {
                    foreach ($patterns as $pattern) {
                        if (str_contains($contents, $pattern)) {
                            $tests[$file] = true;
                            $matchedBy[$kind] = true;

                            continue 3;
                        }
                    }
                }
            }

            if ($tests === []) {
                $untested++;
            }

            $result[] = [
                'method' => (string) ($route['method'] ?? ''),
                'uri' => '/'.$uri,
                'name' => $name !== '' ? $name : null,
                'action' => $action,
                'tests' => array_slice(array_keys($tests), 0, 10),
                'matched_by' => array_keys($matchedBy),
            ];
        }

        return ['total' => count($routes), 'untested' => $untested, 'routes' => $result];
    }

    /** @return array<string, list<string>> */
    private function needles(string $uri, string $name, string $action): array
    {
        $needles = [];

        if ($name !== '') {
            $needles['name'] = ["route('{$name}'", "route(\"{$name}\""];
        }

        $static = strstr($uri, '{', true);
        $static = $static === false ? $uri : rtrim($static, '/').'/';

        if ($static !== '' && strlen($static) >= 3) {
            $needles['uri'] = $static === $uri
                ? ["'/{$uri}'", "\"/{$uri}\"", "'/{$uri}?", "\"/{$uri}?"]
                : ["'/{$static}", "\"/{$static}"];
        }

        if ($action !== '' && $action !== 'Closure' && str_contains($action, '\\')) {
            $class = str_contains($action, '@') ? substr($action, 0, (int) strpos($action, '@')) : $action;
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $needles['controller'] = [$short.'::class', $class];
        }

        return $needles;
    }
}
