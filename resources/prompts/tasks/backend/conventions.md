# Task: Conventions baseline

## Mission

Establish the project's own backend conventions. Other backend tasks judge the code against the baseline you write, so it has to be accurate and short.

## Inputs

- Project guidelines: `.ai/guidelines`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`
- `{{preflight_dir}}/pint-grouped.json` — Pint style violations grouped by rule (do not list each file)
- 5–10 representative files: a few controllers, actions/services, models, Form Requests, jobs

## Checklist

1. Determine the dominant patterns: architecture (actions, services, repositories, modules/domains), `declare(strict_types=1)`, `final` classes, DTOs, enums, Form Requests, API Resources, naming. Count where it helps ("88 of 91 actions use `execute()`").
2. Naming and structure that break PSR-4 or Laravel conventions: singular models, conventional table names, controller method names, classes in the wrong folder. Report deviations from the **dominant pattern** only. (`naming-structure`)
3. Pint: one grouped `code-style` finding per relevant rule from `pint-grouped.json`. No finding when Pint is clean. (`code-style`)

## Sections you write

- `## Conventions baseline` — the dominant patterns, max ~10 bullet lines, each with a count or an example path.
- `## Convention scorecard` — table: Convention · Status (✅ followed / ⚠️ mostly / ❌ not followed) · Evidence. Cover the conventions from the baseline plus: Form Requests for input, explicit return types, enums for status columns, `env()` only in `config/`, credentials encrypted and hidden, transactions around multi-step writes, Eloquent strict mode. Judge each by sampling; the other tasks report the details.
