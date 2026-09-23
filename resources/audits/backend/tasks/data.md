# Task: Models and data exposure

## Mission

Check the Eloquent models and how model data flows in and out: casts, relationships, mass assignment and what gets exposed.

## Inputs

- The conventions baseline (below, from another task)
- The Code candidates snapshot above — the lines that match your checks, with file and line; open its rows for your checks instead of searching the codebase (`request->all()`)
- `app/Models`, Form Requests, controllers and services that call `create()`, `update()`, `fill()`, API Resources, `toArray()`

## Checklist

- Eloquent hygiene: missing relationship return types, missing `casts` (dates, enums, JSON, `encrypted`), mixed accessor styles, duplicated query logic that should be a scope, `Model::shouldBeStrict()` / `preventLazyLoading()` not enabled outside production. (`eloquent-hygiene`)
- Mass assignment: `$request->all()` / `input()` passed into `create()`, `update()`, `fill()` — in controllers and services; `$guarded = []` on models that receive request data. Validated data (`validated()`) is fine. (`mass-assignment`)
- Data exposure: sensitive attributes (password, remember_token, 2FA secrets, API keys, tokens) missing from `$hidden` or not `encrypted`; API Resources or `toArray()` exposing internals. Compare with how the project handles its other credentials. (`data-exposure`)
