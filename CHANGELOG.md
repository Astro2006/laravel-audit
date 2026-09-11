# Changelog

All notable changes to `laravel-audit` will be documented in this file.

## Unreleased

### Changed

- Agents run through drivers named like Laravel Boost's agents: `claude_code`, `opencode` and `codex`. `agent.driver = auto` picks the first installed agent from `boost.json`; `--agent` and `--model` override it per run. Models, including self-hosted ones, are configured in the agent CLI.
- Every audit is split into small tasks (2–7 related checks) that run as separate agent calls in parallel, plus a summary task. Tasks that need another task's output wait for it. The harness assembles one report per audit; a failed task makes the audit `partial` instead of failing it.
- `max_findings` is now `max_findings_per_task` (default 8), `agent.timeout` is per task (default 900 s).
- Prompts moved to `_task.md` and `tasks/<audit>/<task>.md`. Published prompts of the old layout are ignored, with a warning.
- The Boost MCP server no longer needs an `.mcp.json` entry, and `get-config` is never allowed because it returns secret values.

### Fixed

- The routes step crashed on unnamed routes.
- Report lines were split on the byte 0x85, which broke tables containing ✅.
- The PHPStan stub analysed `tests/`, which filled the report with Pest noise.
- PHPStan ignored `--error-format=json` when the audit was started from inside an agent session.
- `tests/Fixtures/project/composer.lock` was never committed, so five tests failed on CI.

## 0.1.0 (unreleased MVP)

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
