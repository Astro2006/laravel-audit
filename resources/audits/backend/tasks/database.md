# Task: Database schema and migrations

## Mission

Check the schema the migrations build and the migrations themselves: indexes, constraints, column types and safe changes.

## Inputs

- The Database schema snapshot above and its detail files — tables, columns, indexes and foreign keys read from `database/migrations`, with candidates. It is a static read: a candidate is a lead, not a finding. A later migration may fix what an earlier one misses.
- Boost `database-schema` if available — the real schema of the local database
- `database/migrations` — open a migration before you report it
- `app/Models` and the queries that use a column — to judge whether an index or a unique constraint matters

## Checklist

- Foreign keys and frequently filtered or sorted columns without an index. PostgreSQL and SQLite do not index foreign keys themselves; MySQL does. Rate by the queries you find, not by theory. (`missing-index`)
- Reference columns (`*_id`) without a foreign key constraint, or with `onDelete` behaviour that leaves orphans or deletes data the app still needs. (`missing-foreign-key`)
- Uniqueness the code relies on without a unique index: `unique` validation rules, `firstOrCreate` / `updateOrCreate`, lookups by e-mail, slug or other natural keys. (`missing-unique`)
- Money in `float` or `double`, dates in string columns, unbounded `text` for short values, `nullable()` columns the code never expects to be null. (`column-type`)
- Migrations without `down()` or with an empty one, `dropColumn` / `Schema::drop` of tables with data, data changes through Eloquent models inside migrations (they break when the model changes). (`migration-safety`)

Old migrations are history: report a problem once, for the table as it is today, not for every migration that touched it.
