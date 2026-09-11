<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Closure;

/**
 * Candidates from route:list — not findings. The frontend agent confirms
 * or rejects each one.
 */
final class RouteFacts
{
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const array MUTATING_ACTIONS = ['store', 'update', 'destroy', 'delete', 'remove'];

    private const string SENSITIVE_FORMS = '/login|register|password|forgot|reset|two-factor|2fa|verif|contact|newsletter|subscribe/i';

    private const string DEBUG_ROUTES = '#^/?(telescope|horizon|pulse|log-viewer|logs|_ignition|_debugbar|__clockwork|clockwork|phpinfo|laravel-websockets)(/|$)#i';

    private const string PUBLIC_WRITES = '#^/?(login|logout|register|forgot-password|reset-password|email/verification-notification|two-factor-challenge|livewire(-[a-z0-9]+)?/|broadcasting/auth|sanctum/|_ignition|_boost/)#i';

    /** @var Closure(string): bool */
    private Closure $actionExists;

    /**
     * @param  (Closure(string): bool)|null  $actionExists  checks "Class@method" / invokable "Class"
     */
    public function __construct(?Closure $actionExists = null)
    {
        $this->actionExists = $actionExists ?? static function (string $action): bool {
            [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];

            return class_exists($class) && method_exists($class, $method);
        };
    }

    /**
     * @param  list<array<string, mixed>>  $routes  decoded route:list --json
     * @return array<string, mixed>
     */
    public function facts(array $routes): array
    {
        $facts = [
            'total' => count($routes),
            'unauthenticated_write' => [],
            'state_changing_get' => [],
            'missing_throttle' => [],
            'debug_routes' => [],
            'broken_routes' => [],
        ];

        foreach ($routes as $route) {
            $methods = $this->methods($route);
            $uri = '/'.ltrim((string) ($route['uri'] ?? ''), '/');
            $name = (string) ($route['name'] ?? '');
            $action = (string) ($route['action'] ?? '');
            $middleware = $this->middleware($route);
            $entry = [
                'method' => implode('|', $methods),
                'uri' => $uri,
                'name' => $name !== '' ? $name : null,
                'action' => $action,
                'middleware' => $middleware,
            ];

            $authed = $this->hasAuth($middleware);
            $writes = array_intersect($methods, self::WRITE_METHODS) !== [];

            if (preg_match(self::DEBUG_ROUTES, $uri) === 1) {
                $facts['debug_routes'][] = [...$entry, 'authenticated' => $authed];

                continue;
            }

            if ($writes && ! $authed && preg_match(self::PUBLIC_WRITES, $uri) !== 1) {
                $facts['unauthenticated_write'][] = $entry;
            }

            if (in_array('GET', $methods, true) && in_array(strtolower($this->actionMethod($action)), self::MUTATING_ACTIONS, true)) {
                $facts['state_changing_get'][] = $entry;
            }

            if ($writes && ! $this->hasThrottle($middleware) && preg_match(self::SENSITIVE_FORMS, $uri.' '.$name) === 1) {
                $facts['missing_throttle'][] = $entry;
            }

            if ($this->isControllerAction($action) && ! ($this->actionExists)($action)) {
                $facts['broken_routes'][] = $entry;
            }
        }

        $facts['flagged'] = count($facts['unauthenticated_write']) + count($facts['state_changing_get'])
            + count($facts['missing_throttle']) + count($facts['debug_routes']) + count($facts['broken_routes']);

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    private function methods(array $route): array
    {
        $methods = explode('|', strtoupper((string) ($route['method'] ?? '')));

        return array_values(array_filter($methods, fn (string $method): bool => $method !== '' && $method !== 'HEAD'));
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    private function middleware(array $route): array
    {
        $middleware = $route['middleware'] ?? [];

        if (is_string($middleware)) {
            $middleware = preg_split('/[\n,]+/', $middleware) ?: [];
        }

        return array_values(array_filter(array_map(fn ($item): string => trim((string) $item), (array) $middleware)));
    }

    /** @param list<string> $middleware */
    private function hasAuth(array $middleware): bool
    {
        foreach ($middleware as $item) {
            if (stripos($item, 'auth') !== false || str_starts_with($item, 'can:') || stripos($item, 'Authorize') !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $middleware */
    private function hasThrottle(array $middleware): bool
    {
        foreach ($middleware as $item) {
            if (stripos($item, 'throttle') !== false) {
                return true;
            }
        }

        return false;
    }

    private function actionMethod(string $action): string
    {
        return str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : '';
    }

    private function isControllerAction(string $action): bool
    {
        return $action !== '' && $action !== 'Closure' && str_contains($action, '\\');
    }
}
