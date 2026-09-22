# Task: Laravel conventions

## Mission

Find places where the backend breaks the project's own conventions or Laravel best practice in a way that has real cost.

## Inputs

- The conventions baseline (below, from another task) — judge against it
- The Routes snapshot above and its `all-routes` file — which controllers are reachable
- The Code candidates snapshot above — the lines that match your checks, with file and line; open its rows for your checks instead of searching the codebase (`env()` calls, inline validation)

## Scope

`app/`, `config/`, `database/seeders`, `bootstrap/app.php`, service providers.

## Checklist

- `env()` called outside `config/` — it returns null once config is cached (`php artisan optimize` / `config:cache`). High-value check: search `app/`, `database/`, `routes/`, `bootstrap/`. (`env-outside-config`)
- Validation inline in controllers (`$request->validate()`, `Validator::make()`) where the project uses Form Requests. Group all occurrences into one finding. (`inline-validation`)
- Business logic in controllers, models or views; fat controllers or god classes; the same logic duplicated next to an existing action or service. (`fat-controller`)
- Magic strings where enums exist or would fit (status, role, type columns without a cast); hardcoded values that belong in config; the same literal repeated across files. (`magic-values`)
