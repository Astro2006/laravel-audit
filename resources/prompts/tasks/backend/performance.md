# Task: Performance

## Mission

Find performance traps in PHP code, queries and the database schema.

## Inputs

- `database/migrations` — indexes; a later migration may add an index
- Boost `database-schema` if available — the real indexes
- Controllers, actions, services, jobs with queries

## Checklist

- N+1 patterns and queries inside loops in PHP classes (lazy loading in views belongs to the frontend audit). (`n-plus-one`)
- `->get()` followed by collection filtering that belongs in SQL, `->get()->count()` instead of `->count()`, whole tables loaded or iterated where a `where` would do, missing `chunk()` / `lazy()` for large datasets. (`inefficient-query`)
- Missing indexes on foreign keys (PostgreSQL and SQLite do not index them automatically) and on frequently filtered or sorted columns; unbounded text columns used in `where`. Check every later migration before claiming an index is missing. (`missing-index`)
- Repeated expensive work (external API calls, heavy queries) without caching; caches without invalidation. (`missing-cache`)

Rate the severity by realistic load for this app, not by theory.
