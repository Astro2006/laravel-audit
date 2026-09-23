---
name: laravel-audit
description: Run the laravel-audit code audit of this Laravel app step by step - preflight tools, the tests, frontend, backend and dependencies audits split into small read-only tasks, project skills per task, and the reports.
---

# Laravel Audit

The audit has two parts. A **preflight** runs the project's own tools (tests, PHPStan, Pint, `composer audit`, `route:list`, …) and condenses their results. Then **tasks** review the code: each task is one short, read-only agent session with a few related checks, started by the harness. You coordinate; you do not audit code yourself.

## Two ways to run it

- `php artisan audit` runs everything in a fixed order without you. Prefer it in CI, or when the user just wants the reports.
- The steps below let you choose steps, tasks and skills and report progress to the user.

## Rules

- Run only `php artisan audit:tool …` commands, one per call. Never chain commands with `;`, `&&`, `|` or `$(…)`.
- Every command prints JSON. Pass `--run=<id>` to every command after `open`.
- Never change files. Never open `.env` files.
- Only one piece of background work runs at a time. A `busy` error means: `wait` first.

## Steps

1. **Open the run.** `php artisan audit:tool open` for every configured audit, or `--audits=backend --audits=dependencies` for some. It checks the requirements and prints the run ID. Show the user any `problems` or `warnings`.
2. **Look at the catalog.** `php artisan audit:tool catalog --run=<id>` lists the preflight steps (`heavy`, `configured_for`), the tasks (`checks`, `depends_on`, `reads_steps`, `skip_reason`) and the project's installed `skills` with their descriptions.
3. **Run the preflight in one call** with the steps the tasks you plan need: `php artisan audit:tool preflight composer routes tests --run=<id>`. Light steps take seconds; `tests`, `browser` and `phpstan` take minutes. Then `php artisan audit:tool wait --run=<id>` until `"done": true`.
4. **The test gate.** If `gate` is `aborted`, tests fail. Tell the user how many (`failing_tests`) and where the details are (`storage/audit/<run>/preflight/details/tests/`), and ask whether to continue anyway. Only on a yes: `php artisan audit:tool continue --run=<id>`. On a no: stop and say so.
5. **Pick skills per task.** Call `catalog` again: `skip_reason` now names tasks with nothing to audit; leave them out. For each task, pick at most `skills_per_task` skills whose description covers what the task checks (a models skill for model hygiene, a PHPStan skill for the PHPStan task). Most tasks need none.
6. **Start the tasks in batches.** `php artisan audit:tool start backend.conventions backend.data:models,php --run=<id>`: a task key, optionally followed by `:` and its skills. Start tasks listed in another task's `depends_on` first. Then `wait` until `"done": true`. The harness runs at most `agent.max_concurrency` tasks of a batch at once (1 by default); you do not need to split batches for that.
7. **Check the results.** `status` shows every task; `php artisan audit:tool result <task> --run=<id>` shows its findings. A `failed` task may be started once more.
8. **Finish.** `php artisan audit:tool finish --run=<id>` writes one report per audit and `run.md`. Tasks you never started are listed as not started.

## Reading the results

- `run.md` has the overview, metrics, every task and a finding index; `<audit>.md` has the findings with evidence.
- **Severity** says how bad a problem is (`critical` … `info`); **confidence** how sure the task is (`confirmed`, `likely`, `suspected`). Point the user to critical and high findings first.
- Findings the harness could disprove (a file that does not exist, quoted code that is not in the file) are listed under "Rejected findings" and are not in the reports.
- Tell the user which tasks you left out and why.

## Local models

A local model server usually answers one request at a time. Keep `agent.max_concurrency` at `1` (the default) for it; raise it only for a hosted API or a server that serves requests in parallel.
