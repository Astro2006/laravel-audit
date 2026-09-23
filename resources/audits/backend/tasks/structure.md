# Task: Structure and responsibilities

## Mission

Find business logic in the wrong place and values that belong in enums or config, where it has real cost.

## Inputs

- The conventions baseline (below, from another task) — judge against it
- The Routes snapshot above and its `all-routes` file — which controllers are reachable; start with the biggest ones

## Scope

`app/Http/Controllers`, `app/Models`, actions and services. Open a few representative classes, not every file.

## Checklist

- Business logic in controllers, models or views; fat controllers or god classes; the same logic duplicated next to an existing action or service. (`fat-controller`)
- Magic strings where enums exist or would fit (status, role, type columns without a cast); hardcoded values that belong in config; the same literal repeated across files. (`magic-values`)
