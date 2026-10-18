# Task: Route hygiene

## Mission

Confirm or reject the route candidates the preflight found and check route-level protections.

## Inputs

- The Routes snapshot above — **candidates, not findings**: GET routes with state-changing action names, auth and form routes without `throttle`, debug/tooling routes, routes pointing to missing controller methods
- Its `all-routes` file, `routes/*.php`, `bootstrap/app.php` (CSRF exclusions, middleware)

## Checklist

- State-changing actions via GET. (`state-changing-get`)
- Missing `throttle` on login, registration, password reset, contact forms and API. (`missing-throttle`)
- CSRF exclusions: justified? (`csrf-exclusion`)
- Debug and tooling routes reachable in production (Telescope, Horizon, Pulse, log viewers, `/test`, `/phpinfo`) without a gate. (`debug-route`)
- Routes pointing to missing controllers or methods, duplicate or conflicting URIs. (`broken-route`)
