---
name: laravel-audit
description: Run the laravel-audit code audit of this Laravel app as its orchestrator - preflight tools first, then the read-only tasks stage by stage (analyse, coding standards, security), then the reports - through `php artisan audit:tool`.
---

# Laravel Audit

You orchestrate the audit; you do not audit code yourself. A **preflight** runs the project's own tools (tests, PHPStan, Pint, `composer audit`, `route:list`, …). Then **tasks**, short read-only agent sessions the harness starts, review the code. You decide which tasks run, in which order, what to rerun and when to stop.

## Rules

- Run only `php artisan audit:tool …`, one command per call. Never chain commands (`;`, `&&`, `|`, `$(…)`). Never change files, never open `.env`.
- Every command prints JSON. Pass `--run=<id>` to every command after `open`. Every answer has `next`: the command the harness would run now. Follow it when in doubt.
- Only one piece of background work runs at a time; `busy` means `wait` first.
- `php artisan audit` runs everything in a fixed order without you. Prefer it in CI.

## Stages

Work stage by stage. Within a stage you are free.

| Stage | Question | Why this order |
|---|---|---|
| 0 preflight | What can tools prove? | the tasks read the results instead of searching |
| 1 analyse | What do the tool results mean, how is the app built? | writes the conventions baseline and the frontend map the next stage judges against |
| 2 standards | Where does the code deviate from Boost's guidelines and the project's conventions? | builds on stage 1 |
| 3 security | What can be exploited or lose data? | independent, so a paused run has the cheaper stages done |
| 4 think | What matters, in which order? | `finish` writes the reports; the verify passes already ran per task |

## Commands

| Command | What it does | Then |
|---|---|---|
| `open [--audits=backend --audits=…] [--force]` | checks the requirements, creates the run, prints its ID | `catalog` |
| `catalog` | preflight steps, tasks grouped by `stages` with `checks`, `depends_on`, `skip_reason`, the installed `skills` | `preflight` |
| `preflight <step> …` | runs these steps in the background (`tests`, `phpstan`, `browser` take minutes) | `wait` |
| `wait` | blocks until the worker is idle; reports `finished_since`, `failed_since`, `pending`, `spend_usd`, `paused` | its `next` |
| `continue` | continues past failing tests, only after the user said yes | `start` |
| `start --stage=<stage>` · `start <task>[:skill,…] …` · `start --pending` · `start --failed` | starts the tasks of a stage, named tasks, every task not run, or every failed task once more | `wait` |
| `status` | the full table: gate, stages, every task, spend, pause | – |
| `result <task>` | the findings of one task | – |
| `finish` | writes the reports and `run.md` | read them |

## Decisions you make

- **Scope.** Leave out audits the project has no material for (no frontend, no browser tests) and tasks with a `skip_reason`.
- **Gate.** `gate: aborted` means tests fail. Tell the user how many (`failing_tests`) and where (`preflight/details/tests/`), and ask whether to continue. Only on a yes: `continue`. Without a user to ask, stop and report.
- **Rerun.** A `failed` task once (`start --failed`). A `completed` task with zero findings while the preflight shows material (advisories, failing tests) once more. The harness allows two starts per task.
- **Skills.** Tasks you start without `:skill` get the harness's own selection. Name a skill only when you know it fits the task.
- **Budget.** Watch `spend_usd`. When the user's budget is near, stop starting tasks and say so.
- **Pause.** `paused` in `status` or `wait` means a usage limit or the run budget stopped the run. Tell the user the `message` and `resets_at`; after the reset, `start --pending`. Do not retry before.

## When you are done

After `finish`, read `run.md` and tell the user: the findings to fix first (by ID and title, critical and high first), the tasks that failed or were left out and why, and what the run cost (`spend_usd`). Findings the harness disproved are under "Rejected findings" in `run.md`; they are not in the reports.
