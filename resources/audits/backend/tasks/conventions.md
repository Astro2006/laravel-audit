# Task: Conventions baseline

## Mission

Establish the project's own backend conventions. Other backend tasks judge the code against it, so keep it accurate and short.

## Inputs

- Project guidelines: `.ai/guidelines`, `.ai/rules`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`
- The Pint snapshot above — style violations grouped by rule (do not list each file)
- 5–10 representative files: controllers, actions/services, models, Form Requests, jobs

## Checklist

1. Determine the dominant patterns as **decisions, not defaults**: a pattern needs three consistent examples, no meaningful rival, and a real choice among Laravel's options; framework defaults are not conventions. Decide: validation entry point; controller shape; where business logic lives and how actions are called; `$fillable` vs `$guarded`; accessor style; DTO style; injection vs `app()`; helpers vs facades; enums; strict types and `final`. Count ("88 of 91 actions use `execute()`").
2. Naming and structure that break PSR-4 or Laravel conventions: singular models, conventional table names, controller method names, classes in the wrong folder. Report deviations from the **dominant pattern** only; a few outliers are a scorecard row, not a finding. (`naming-structure`)
3. Pint: one grouped `code-style` finding per relevant rule from the Pint snapshot. No finding when Pint is clean. (`code-style`)

## Sections you write

- `## Conventions baseline` — the dominant patterns, max ~10 bullet lines, each with a count or an example path.
- `## Convention scorecard` — table: Convention · Status (✅ followed / ⚠️ mostly / ❌ not followed) · Evidence. Cover the baseline, the Coding standards above when present, plus: Form Requests for input, explicit return types, enums for status columns, `env()` only in `config/`, credentials encrypted and hidden, transactions around multi-step writes, Eloquent strict mode. Judge by sampling.
