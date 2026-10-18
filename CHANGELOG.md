# Changelog

All notable changes to `laravel-audit` will be documented in this file.

## Unreleased

### MVP3 (in progress, see `docs/MVP3.md`)

- **Stages.** Every task in `audit.php` has a `stage`: `analyse`, `standards` or `security` (default `standards`). `audit:tool catalog` groups the tasks by stage, `status` shows the progress per stage, and `start --stage=<stage>` starts every task of a stage that has not run. `start --pending` and `start --failed` start every task that has not run, or every failed task once more.
- **Orchestrator primitives.** Every `audit:tool` answer carries `next`, the command the harness would run now. `wait` reports only what finished since the last `wait` (`finished_since`, `failed_since`); `status` keeps the full table and adds `spend_usd` and `pending`. A task can be started at most `agent.max_attempts` (2) times per run; a run accepts at most `tool.max_calls` (400) `audit:tool` calls, after which only `status`, `wait` and `finish` work. Tasks started without `:skills` get the harness's own skill selection, one agent call per run.
- **Resume.** `php artisan audit` writes every task outcome to `storage/audit/<run>/tool/tasks/` as it finishes, the same store `audit:tool` uses. `php artisan audit --resume[=<run>]` continues the latest run, or the given one: tasks with a completed result keep it, every other task runs again, then the reports are written.
- **Pause.** When the agent CLI reports a usage or rate limit, the run stops starting tasks, keeps the unfinished ones pending, writes `storage/audit/<run>/tool/pause.json` and ends with exit code 4 and the resume command. An interactive `php artisan audit` offers to wait for the reset and continue in the same process. `agent.run_budget_usd` (`AUDIT_RUN_BUDGET_USD`) pauses the run before a task that would push its total cost above the amount. In `audit:tool`, `status` and `wait` show the pause, and `start` refuses until a known reset time has passed.
- **Boost guidelines as the coding standard.** The harness reads the rule blocks Boost wrote into `AGENTS.md` (or `CLAUDE.md`), leaves out the tooling blocks (`foundation`, `boost`, `herd`, `deployments`, `tests`, the audit's own), adds the project's `.ai/rules` and `.ai/guidelines`, and inlines them as `## Coding standards` into the standards-stage tasks and the conventions baseline, capped at `standards_max_chars` (6000). `agent.standards` is `boost` (default), `none` or a list of files. The conventions task now records decisions, not defaults, following Boost's own method.
- **Internals.** `AgentRunner` is split into the loop, `JobQueue` (critical-first order, readiness) and `FollowUp` (repair, answer-now and open-files decisions). A new arch test keeps every source file at or below 250 lines.

### MVP2

- **Boost skill and guideline.** The package ships `resources/boost/guidelines/core.blade.php` and the skill `laravel-audit`, which tells your own Claude Code or OpenCode session how to run the audit with `php artisan audit:tool`. `audit:install` registers both with Boost: interactive through `boost:update --discover`, with `--yes` by adding the package to `boost.json`.
- **`audit:tool open`, `continue` and `finish`** create a run, continue past failing tests after the user agreed, and write the reports. `start` accepts `task:skill,skill`.
- **Project skills per task.** The harness finds the skills Boost installed, one agent call per run assigns at most `skills_per_task` of them to the tasks they fit, and each task gets their text in its prompt. The choice is reused while skills and tasks stay the same (`--reselect-skills` asks again); `agent.skills` = `none` or a map switches it off or fixes it.
- **Removed:** the Codex driver, for now: it works through a shell and cannot keep agents away from `.env`. A published `agent.drivers.codex` block is reported as no longer read.
- **Removed:** the headless orchestrator (`orchestrator => agent`, `--orchestrate`). `agent.orchestrator.*` and `orchestrator` are reported as no longer read.
- **`agent.max_concurrency`** (default 1) and `--concurrency` replace `agent.mode`, `agent.concurrency` and `--mode`.
- **Verify calls** are limited to `agent.verify_max_turns` (6) tool calls.
- **Layout:** each audit's definition and prompts live in `resources/audits/<audit>/` (`audit.php`, `tasks/`), shared prompts in `resources/audits/shared/`. Both publish tags publish into `resources/audits/vendor/laravel-audit`; copies of the old layout are ignored with a warning. The audit classes are `Audits\BackendAudit` etc.
- **Fixed:** `deny_read` globs now support `**` (default `storage/**/*.key`). The database guard read `.env.testing` whenever it existed; it now follows Laravel (`.env.<APP_ENV>`) and reads `DB_URL` and `DB_PORT`. The test run lost variables exported in the shell when `.env` named them too. Locations like `file.php:12–14` or `file.php#L12` were rejected as hallucinated. Lines with characters such as ✅ were split in the evidence check. Guest-only routes counted as authenticated. PHPStan showed "no errors" when it crashed. Background tools could stall on a full output pipe. The `audit:tool` state files could be read half-written.

### Changed

- Agents run through drivers named like Laravel Boost's agents: `claude_code`, `opencode` and `codex`. `agent.driver = auto` picks the first installed agent from `boost.json`; `--agent` and `--model` override it per run. Models, including self-hosted ones, are configured in the agent CLI.
- Every audit is split into small tasks (2–7 related checks) that run as separate agent calls, plus a summary task. Tasks that need another task's output wait for it. The harness assembles one report per audit; a failed task makes the audit `partial` instead of failing it.
- `max_findings` is now `max_findings_per_task` (default 8), `agent.timeout` is per task (default 900 s).
- Prompts moved to `_task.md` and `tasks/<audit>/<task>.md`. Published prompts of the old layout are ignored, with a warning.
- The Boost MCP server no longer needs an `.mcp.json` entry, and `get-config` is never allowed because it returns secret values.
- Task logs (`logs/<task>.json`) are always one formatted JSON document. The JSON Lines streams of OpenCode and Codex are stored as a list of their events.
- Each audit lists its preflight steps in `audits.<audit>.preflight` (`audits.<audit>.enabled` turns it on). Only listed steps run, the test suite included. `preflight.pint`, `preflight.browser.enabled` and `preflight.phpstan.enabled` are gone.

### Added

- Laravel Herd's coverage (Xdebug) counts as a coverage driver. The test step passes Herd's `debug.ini` as `PHPRC`, so the processes `artisan test` and paratest start load Xdebug too.
- The harness drops findings it can disprove, without a repair pass: nonexistent files, quoted code that is not in the file, `missing:` files that exist, and dependency findings composer contradicts. `run.md` lists them under "Rejected findings".
- A verify pass per task (`agent.verify`, on by default) checks findings of medium severity and above with the same agent and keeps, downgrades or drops them.
- `missing:path` locations for findings about a file that should exist.
- Tasks with nothing to audit (no advisories, no PHPStan errors, all tests green) are skipped without an agent call.
- `run.md` shows the wall-clock time per task.
- `agent.mode` (`AUDIT_MODE`) and `--mode`: `single` runs one task at a time and is the default, because self-hosted model servers and subscription CLIs rarely handle parallel sessions; `parallel` runs up to `agent.concurrency` (`AUDIT_CONCURRENCY`) tasks at once. The header shows the mode.
- Live progress: the console lists all tasks of the run before the first starts, then shows what runs (with its time and repair passes), what waits and what is done, redrawn in place. Preflight steps show while they run. Without a terminal every start and finish is a plain line.

### Changed

- The harness writes each audit's summary itself (`summary => 'generated'`); `'agent'` restores the summary task. The tests audit keeps its summary task for "Top 10 missing tests".
- `max_findings_per_task` defaults to 5; `max_files_per_task` (25) limits how many files an agent opens.
- The output template no longer contains real-looking example paths that weak models copied as findings.
- Findings that shared a key within one report get distinct keys.

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
