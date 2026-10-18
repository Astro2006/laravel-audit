# Task: Performance

## Mission

Find performance traps in PHP code and queries. Indexes belong to the database task.

## Inputs

- Controllers, actions, services, jobs with queries

## Checklist

- N+1 patterns and queries inside loops in PHP classes (lazy loading in views belongs to the frontend audit). (`n-plus-one`)
- `->get()` followed by collection filtering that belongs in SQL, `->get()->count()` instead of `->count()`, whole tables loaded or iterated where a `where` would do, missing `chunk()` / `lazy()` for large datasets. (`inefficient-query`)
- Repeated expensive work (external API calls, heavy queries) without caching; caches without invalidation. (`missing-cache`)

Rate the severity by realistic load for this app, not by theory.
