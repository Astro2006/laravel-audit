# Task: Database, Eloquent and Queries

## Mission

Check database structures, query performance, Eloquent usage, data hydration efficiency and data integrity rules.

## Inputs

- The `database_schema` and active migrations layout (tables, column data types, indexes)
- `app/Models` (relationships, fillables, casts, model lifecycle hooks/observers)
- Repository classes, Action classes, or Controllers executing heavy or manual database queries
- `config/database.php` and database-related variables in `.env.example`

## Checklist

- **N+1 Queries:** Models or loops that trigger separate database queries for relationships instead of using eager loading (`with()`, `load()`). Watch out for accessor methods (`getAttribute`) that hit the database. (`n-plus-one`)
- **Missing Indexes:** Frequently filtered columns (`where()`), sorting columns (`orderBy()`), and foreign keys that lack an index in the migration file, causing full table scans under load. (`missing-index`)
- **Mass-Assignment & Mutation:** Models using `guarded = []` dangerously, missing or incorrect `$casts` (e.g., JSON arrays, booleans, dates stored as raw strings), or lack of atomic database transactions (`DB::transaction`) during multi-row updates. (`data-integrity`)
- **Raw SQL Injections:** Direct usage of `DB::raw()`, `whereRaw()`, or `orderByRaw()` containing unescaped user inputs or concatenated string variables instead of proper parameter binding. (`raw-sql-injection`)
- **Query Efficiency:** Missing pagination on large datasets (`all()` or `get()` instead of `paginate()`), heavy data hydration where only specific columns are needed (`select()` or `pluck()` missing), and inefficient chunking for large background data processing. (`inefficient-query`)
