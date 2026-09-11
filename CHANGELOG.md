# Changelog

All notable changes to `laravel-audit` will be documented in this file.

## Unreleased

First MVP.

### Added

- `php artisan audit` with four read-only Claude Code agents: tests, frontend, backend and dependencies.
- Deterministic preflight: app info, stack, lines of code, Pint, routes and route facts, translation diff, Composer facts and usage map, tests with JUnit and coverage, Pest browser tests with Playwright, Larastan.
- Test gate (`ask`, `abort`, `continue`) and a database guard that skips tests when they could hit the app database.
- Child processes start without the variables loaded from the app's `.env`, so tests resolve their environment like in a fresh shell.
- `--without` for audits and optional steps, `--force`, `--preflight-only`, `--reuse-preflight`.
- Report format v1 with a harness-owned frontmatter, a linter, one repair pass, required findings from preflight facts, stable finding keys and possible-duplicate detection.
- `run.md` and `run.json` with metrics, telemetry, experimental risk points and a finding index.
- `php artisan audit:install` with one bundled Composer and npm call, a Composer side-effect preview, separately confirmed upgrades and a Larastan stub.
- Hidden `php artisan audit:eval` to measure recall, false alarms, stability and cost against a fixture app.
- English and German console output.
