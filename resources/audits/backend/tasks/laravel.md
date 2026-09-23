# Task: Laravel conventions

## Mission

Find `env()` calls that break with a cached config and inline validation where the project uses Form Requests.

## Inputs

- The conventions baseline (below, from another task) — judge against it
- The Code candidates snapshot above — every `env()` call outside `config/` and every inline validation, with file and line. Open those lines; you do not need to search the codebase.

## Checklist

- `env()` called outside `config/` — it returns null once config is cached (`php artisan optimize` / `config:cache`). Group all occurrences into one finding. (`env-outside-config`)
- Validation inline in controllers (`$request->validate()`, `Validator::make()`) where the project uses Form Requests. Group all occurrences into one finding. (`inline-validation`)
