# Task: Route access control

## Mission

Check who can reach which route and whether ownership is enforced.

## Inputs

- `{{preflight_dir}}/routes.json` — all routes with methods, names, actions and middleware
- `{{preflight_dir}}/route-facts.json` — **candidates, not findings**: write routes without `auth` and more. Confirm or reject each candidate you touch.
- Controllers and Form Requests as entry points, `app/Policies`, `bootstrap/app.php`, `app/Http/Middleware`

## Checklist

- Routes handling user data without `auth`; missing `verified`, `can:` / policy checks or `password.confirm` on sensitive actions; admin areas protected only by hiding UI elements; Form Requests whose `authorize()` returns `true` without another check. (`route-authorization`)
- Route model binding without an ownership check, no `scopeBindings()` for nested resources, `withTrashed()` exposure. (`idor`)
- Signed URLs for unsubscribe, downloads, invitations. (`signed-url`)
- Webhooks without signature verification. (`webhook-signature`)

Mass assignment and SQL injection belong to the backend audit, also when they start at a route.

## Section you write

- `## Route risk table` — Route · Methods · Middleware · Risk notes. Only sensitive routes, candidates you confirmed or rejected, and routes with findings; max 30 rows.
