<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Routes\RouteDefinition;
use Astro2006\LaravelAudit\Preflight\Data\Routes\RouteFactsReport;
use Closure;
use Illuminate\Support\Str;

final class RouteFacts
{
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const array MUTATING_ACTIONS = ['store', 'update', 'destroy', 'delete', 'remove'];

    private const array AUTH_ALIASES = ['auth', 'auth.basic', 'can', 'verified'];

    private const array AUTH_CLASSES = ['Authorize', 'EnsureEmailIsVerified'];

    private const string SENSITIVE_FORMS = '/login|register|password|forgot|reset|two-factor|2fa|verif|contact|newsletter|subscribe/i';

    private const string DEBUG_ROUTES = '#^/?(telescope|horizon|pulse|log-viewer|logs|_ignition|_debugbar|__clockwork|clockwork|phpinfo|laravel-websockets)(/|$)#i';

    private const string PUBLIC_WRITES = '#^/?(login|logout|register|forgot-password|reset-password|email/verification-notification|two-factor-challenge|livewire(-[a-z0-9]+)?/|broadcasting/auth|sanctum/|_ignition|_boost/)#i';

    private const string FRAMEWORK_SIGNED_ROUTES = '/^storage\.[\w-]+\.(serve|upload)$/';

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

    /** @param list<RouteDefinition> $routes */
    public function facts(array $routes): RouteFactsReport
    {
        $unauthenticatedWrite = [];
        $stateChangingGet = [];
        $missingThrottle = [];
        $debugRoutes = [];
        $brokenRoutes = [];

        foreach ($routes as $route) {
            $methods = $route->methods();
            $authed = $this->hasAuth($route->middleware);
            $writes = array_intersect($methods, self::WRITE_METHODS) !== [];

            if (preg_match(self::DEBUG_ROUTES, $route->uri) === 1) {
                $debugRoutes[] = $route;

                continue;
            }

            if ($writes && ! $authed && preg_match(self::PUBLIC_WRITES, $route->uri) !== 1 && preg_match(self::FRAMEWORK_SIGNED_ROUTES, $route->name ?? '') !== 1) {
                $unauthenticatedWrite[] = $route;
            }

            if (in_array('GET', $methods, true) && in_array(strtolower($route->actionMethod()), self::MUTATING_ACTIONS, true)) {
                $stateChangingGet[] = $route;
            }

            if ($writes && ! $this->hasThrottle($route->middleware) && preg_match(self::SENSITIVE_FORMS, $route->uri.' '.$route->name) === 1) {
                $missingThrottle[] = $route;
            }

            if ($route->isControllerAction() && ! ($this->actionExists)($route->action)) {
                $brokenRoutes[] = $route;
            }
        }

        return new RouteFactsReport(count($routes), $unauthenticatedWrite, $stateChangingGet, $missingThrottle, $debugRoutes, $brokenRoutes);
    }

    /**
     * route:list names middleware by alias or by class, with parameters
     * after a colon. Only middleware that turns guests away counts;
     * RedirectIfAuthenticated (guest) and AuthenticateSession do not.
     *
     * @param  list<string>  $middleware
     */
    private function hasAuth(array $middleware): bool
    {
        foreach ($middleware as $item) {
            $name = Str::before($item, ':');
            $class = Str::afterLast($name, '\\');

            if (in_array($name, self::AUTH_ALIASES, true)
                || in_array($class, self::AUTH_CLASSES, true)
                || (str_starts_with($class, 'Authenticate') && $class !== 'AuthenticateSession')) {
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
}
