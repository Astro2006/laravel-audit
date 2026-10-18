# astro2006/laravel-audit – MVP3: Orchestrator agent, stages, cheap models, pause and resume

> Status: concept v1 · 2026-10-18 · Basis: `MVP2.md`, the package at `113cf78` (branch `refactor/orchestrator`), the homemanager backend run `2026-09-24_07-00-14`, probes against Claude Code 2.1.302 and OpenCode 1.19.0 on 2026-10-18.
>
> Every claim below is either verified against the code or a probe, or marked **assumed** or **open**. Nothing in this document is implemented yet.

## 0. Summary

The brief, in one paragraph: an orchestrator agent that is free in how it works but follows four stages (analyse → coding standards → security → think and summarise), manages the 60+ agent calls of a run, dispatches and reruns them as it sees fit, works on low-tier thinking models (Haiku-class for testing; Gemma 4 31B, DeepSeek 4 Flash and Qwen 3.8 27B in daily use), pauses on a plan limit and resumes without loss, stays cheap enough for subscription plans, uses Boost's skills and guidelines, and is built test-first in small files.

MVP2 already delivers most of the machinery: `php artisan audit:tool` (open, catalog, preflight, continue, start, wait, result, finish), a worker with a lock, a task store, skill discovery and inlining, read-only sandboxed task sessions, a report validator with one repair pass, an evidence checker, a verify pass per task and an eval harness. 351 tests are green. MVP3 does not add a second harness. It changes nine things:

1. **Stages.** Every task in `audit.php` gets a `stage` (`analyse`, `standards`, `security`). `finish` is the fourth stage. The orchestrator works stage by stage and is free within a stage (§3.3).
2. **One orchestrator brain, two hosts.** The brain is the `laravel-audit` skill text. Host A is the user's own Claude Code or OpenCode session (exists). Host B is a new, thin `php artisan audit:orchestrate` that starts the configured CLI with the skill as its prompt and only `audit:tool` as its tool (§3.2). Host B is what makes an unattended Haiku test possible.
3. **Orchestrator primitives in `audit:tool`.** `start --stage=…`, `start --pending`, `start --failed`, an attempt cap per task, `wait` that returns only what changed, `status` with spend and pause state, and a `next` hint in every answer (§4.1).
4. **One task store for both flows.** `php artisan audit` persists every task outcome as it finishes, like `audit:tool` already does, and gets `--resume` (§4.2).
5. **Pause and resume.** A plan or rate limit, an exhausted run budget or a crash leaves the run on disk in a resumable state. Interactive runs offer to wait for the reset (§4.3).
6. **Boost guidelines as the coding standard.** The harness extracts the code-related rule blocks Boost wrote into `AGENTS.md` and `.ai/rules` and inlines them into the standards-stage tasks (§4.4).
7. **Report quality.** The reports of the last run were hard to use even on Opus 5.5: a one-line summary, 34 merged limitation bullets with stale IDs, duplicated out-of-scope notes, findings ordered by task instead of severity. §4.6 lists every observed defect with a deterministic fix in the assembler, at zero model cost. The weak-model fixes from the review of that run (per-task turn caps, verify by category, project context, an overview that names findings) are in §4.5.
8. **Cost.** The model is the 40× lever; turn caps and verify policy are 20–30%; the orchestrator's own context is kept small by design (§6). All acceptance runs use Claude Code with Claude Haiku 5.5. OpenCode stays a supported driver but is not part of MVP3's verification (user decision 2026-10-18).
9. **Maintainability.** A 250-line cap enforced by an arch test, `AgentRunner` split in three, tests before code in every phase (§8).

## 1. Where we stand

| # | Fact | Evidence |
|---|---|---|
| F1 | A full run has 34 tasks: tests 7, frontend 11, backend 12, dependencies 4. With one verify job per task, the tests summary task and the results job that is 70 agent calls. | `resources/audits/*/audit.php`, `Run/JobPlanner` |
| F2 | The homemanager backend run on Claude Opus 5.5: 12 tasks, 250 turns, 544k input, 4.8M cache-read, 77k output tokens, **$6.84**, 11.7 min, 22 findings (5 medium, 16 low, 1 info). | `homemanager/storage/audit/2026-09-24_07-00-14/run.md` |
| F3 | Two tasks, `backend.dead-code` and `backend.database`, hit `max_turns` 15, were rescued by the answer-now follow-up and cost $1.45 each: **42 % of the run**. | same `run.md`, Tasks table |
| F4 | All 12 verify jobs said "Nothing to do": `VerifyPolicy` needs severity rank − confidence rank ≥ 2, so `medium` + `likely` is never verified, not even for security. | `Reports/VerifyPolicy.php` |
| F5 | The run overview says it "can't name or rank specific findings": `OverviewPrompt` gets one count line per audit, no IDs or titles. | `Run/TaskRequests::results()`, `run.md` Overview |
| F6 | A rendered task prompt is 11.5–16.4 KB (3–4k tokens). | `logs/*.prompt.md` of that run |
| F7 | Claude Opus 5.5 costs $4 / $20 per MTok, Claude Haiku 5.5 $0.10 / $0.50 (prompts ≤ 100K): **40× cheaper**. Haiku 5.5 thinks by default (adaptive, effort `medium`), 1M context. | model table of 2026-10-06 |
| F8 | Claude Code runs headless on Haiku 5.5: `claude -p --model claude-haiku-5-5 --restricted --tools ""` answered "OK" for **$0.00046** (2,253 cache-creation, 649 cache-read tokens). Its JSON carries `modelUsage.<model>.contextWindow` and `terminal_reason`. | probe 2026-10-18 |
| F9 | OpenCode answers through the user's `codebar/qwen3.8:27b`. The one-line probe cost **15,388 input tokens**: OpenCode's system prompt and tool schemas dominate every turn on a local model. `gemma4:31b` and `qwen3.6:27b` are configured too; DeepSeek 4 Flash is not. | probe 2026-10-18, `~/.config/opencode/opencode.jsonc` |
| F10 | Claude Code's limit messages contain "usage limit reached", "you have reached your weekly usage limit", `rate_limit_error`, `overloaded_error`, "credit balance too low", "Budget limit reached ($". How they appear in `-p --output-format json` is **assumed**: `is_error: true`, text in `result`. | `strings` on the 2.1.302 binary |
| F11 | Boost 2.10 writes rule blocks into `AGENTS.md`/`CLAUDE.md`: `=== php rules ===`, `=== laravel/core rules ===`, `=== pest/core rules ===`, `=== pint/core rules ===`, `=== livewire/core rules ===`, `=== tests rules ===`, plus tooling blocks (`boost`, `herd`, `deployments`, `foundation`). Project decisions live in `.ai/rules` with an `index.md`. Boost ships an `infer-conventions` skill with a 49-dimension checklist and the rule "record decisions, not defaults". | homemanager `AGENTS.md`, `vendor/laravel/boost/.ai/` |
| F12 | `php artisan audit` keeps task outcomes in memory until `FinalizeRun`. Only `audit:tool` writes them to disk (`Tool/TaskStore`). A crash or limit at task 30 of 34 loses everything in the fixed flow. | `Run/Phases/RunAgents.php`, `Tool/Work.php` |
| F13 | The largest source files: `AgentRunner` 263 lines, `InstallConsole` 242, `AuditConsole` 234, `RunMarkdown` 228, `Audit` 227, `AuditConfig` 213. | `wc -l src/**/*.php` |
| F14 | The eight tasks of `improvement-prompt.md` in the run folder (overview with IDs, summary with medium findings, verify by category, per-task `max_turns`, project context, linter warnings into repair, ID check, local-model docs) are not implemented. | `grep -rn "project.context\|verify.threshold\|'max_turns'" src config resources` → only the global key |
| F15 | A completed task can already be started again through `audit:tool start`: the worker recomputes the batch and overwrites the stored outcome. There is no cap on attempts. | `Tool/Work::tasks()` (`except: $keysInBatch`), `Tool/StartWork` |

## 2. Goals, non-goals, acceptance

### 2.1 Goals (each is measured in phase 8 and written into §12)

| # | Goal | Done when |
|---|---|---|
| G1 | A full run (all four audits) of the e2e app completes unattended on **Claude Haiku 5.5** through `audit:orchestrate`. | every task `completed` or `failed` with a reason; ≤ 2 failed; cost ≤ $1.50; orchestrator session ≤ 15 % of the run's cost |
| G2 | The assembled reports of the Haiku run pass the report quality checklist of §4.6. | the summary names at least three findings by ID; no task-local ID survives in prose; findings are ordered by severity; at most three limitation bullets per task and no duplicates; a `## Checks` section per audit |
| G3 | A run interrupted by a limit, a budget or a crash resumes without repeating finished tasks. | the resumed run's reports contain the same tasks as an uninterrupted run; only pending tasks ran again |
| G4 | The orchestrator reruns a failed task once and leaves it alone afterwards. | log shows two attempts at most for any task |
| G5 | Recall on the e2e app's planted findings is measured for Haiku 5.5 and for Opus 5.5 and does not drop between phases. | `audit:eval` numbers in §12 |
| G6 | No source file above 250 lines; every behaviour change has a test written before the code. | `tests/Core/FileSizeTest` green, PR diffs show test first |

### 2.2 Non-goals

- No new audits or checks beyond the eight fixes in §4.5.
- The orchestrator never reads project code. Only the sandboxed task sessions do.
- No fan-out from inside the orchestrator session (subagents). Concurrency stays in the harness (`agent.max_concurrency`), as decided in MVP2 §6.
- No new Composer dependency, no database, no web UI.
- The audits are not re-cut into stage-shaped audits (§3.3 explains why).

### 2.3 Interpretation of the brief

- "Analyse (from the checks that aren't possible to automate)": the preflight runs everything a tool can decide (tests, PHPStan, Pint, `composer audit`, routes, migrations, code candidates). The analyse stage is the agent work that interprets those results and establishes how the app is built. It is the first stage because the later stages build on it.
- "Coding standards (from the Boost guidelines)": the rule blocks Boost generates for the project (F11) are the standard. The project's own conventions, as found by the analyse stage, complete it. A convention the project follows consistently is not a finding, as today.
- "Thinking about it and generating the summary": the verify pass, the per-audit summaries, the cross-audit overview and the orchestrator's own closing message to the user.
- "Low tier models with thinking that are not coding models": the orchestrator only reads JSON and decides, so a general reasoning model is enough. Task sessions read code, but the preflight has already found the risky lines (code candidates, routes, advisories), so a general model with a few file reads does the job. Haiku 5.5 is the test model: Claude Code's current model list has no Haiku 3, and 5.5 is the cheapest Claude model there is (F7, F8).

## 3. The orchestrator

### 3.1 Role

The orchestrator is an agent session that calls `php artisan audit:tool …`, reads the JSON it gets back, decides what to do next and reports to the user. The harness stays the safety net: it validates reports, rejects invented evidence, enforces the sandbox, persists state and refuses only what is impossible (an unknown task, a third attempt, a start while paused). The orchestrator owns the decisions: which tasks, in which order within a stage, which to rerun, when to stop, what to tell the user.

"Not bound by anything" means: the harness never enforces an order. Dependencies between tasks are automatic (the runner waits for them inside a batch and warns when they did not run), so any order the orchestrator picks produces a valid run. The skill explains the four stages and why they are in that order, and that is the only structure it is given.

### 3.2 One brain, two hosts

| | Host A: the user's session | Host B: `php artisan audit:orchestrate` |
|---|---|---|
| Exists | yes (MVP2, Boost skill) | new |
| Who runs the agent CLI | the user, interactively | the command, headless |
| Prompt | the skill, loaded by Boost | the skill body plus the run ID, rendered by the harness |
| Tools | the user's own permissions; the skill forbids everything but `audit:tool` | Claude Code: `--restricted --allowedTools "Bash(php artisan audit:tool:*)" --disallowedTools Read,Edit,Write,Glob,Grep,WebFetch,WebSearch,Agent` (**verify**: the pattern denies chained commands, as documented). OpenCode: a fourth agent `laravel-audit-orchestrator` in the generated config with only `bash` and `permission.bash: {"php artisan audit:tool *": "allow", "*": "deny"}` (**verify once by experiment**, MVP2 §6.2) |
| Model | the session's model | `--model` or `agent.orchestrator.model`, default: the task model |
| Limits | the user's plan | `--max-turns` from `agent.orchestrator.max_turns` (300), `--max-budget-usd` from `agent.orchestrator.max_budget_usd` |
| Output | the agent talks to the user | the final answer is saved as `storage/audit/<run>/orchestrator.md`; exit code from the run status |
| Safety net | `finish` is the user's call | if the session ends without `finish`, the command calls `Finish` itself so the run never ends without reports |

Why a headless host again after MVP2 removed one: the MVP2 orchestrator was a second harness with its own phases, worker and state. This one is one command, one prompt renderer and one command-line builder per driver, because `audit:tool` now is the harness. It is needed for G1 (an unattended Haiku run), for CI, and for the eval harness to compare orchestrated and fixed runs.

Classes (all small): `Console/Commands/OrchestrateCommand`, `Orchestration/OrchestratorPrompt` (skill body + run ID), `Orchestration/OrchestratorSession` (starts the CLI, waits, saves the answer, calls `Finish` if needed), `AgentDriver::orchestratorCommand(AgentRequest)` on both drivers, and the agent entry in `OpenCodeConfig`.

### 3.3 Stages

| Stage | Question | Tasks | Builds on |
|---|---|---|---|
| 0 preflight (harness) | What can tools prove? | `about`, `stack`, `loc`, `pint`, `routes`, `translations`, `migrations`, `candidates`, `composer`, `tests`, `browser`, `phpstan` | – |
| 1 **analyse** | What do the tool results mean, and how is this app built? | `tests.results`, `tests.coverage`, `tests.phpstan`, `tests.tooling`, `dependencies.security`, `dependencies.freshness`, `dependencies.necessity`, `dependencies.hygiene`, `backend.conventions`, `frontend.map` | preflight |
| 2 **standards** | Where does the code deviate from Boost's guidelines and the project's own conventions? | `backend.laravel`, `backend.structure`, `backend.errors`, `backend.jobs`, `backend.performance`, `backend.dead-code`, `backend.database`, `tests.quality`, `tests.suite`, `frontend.ui`, `frontend.a11y`, `frontend.performance`, `frontend.dead-code`, `frontend.errors` | the conventions baseline and the frontend map from stage 1 (`depends_on`, exists) |
| 3 **security** | What can be exploited or lose data? | `backend.injection`, `backend.auth`, `backend.logic`, `backend.data`, `tests.gaps`, `frontend.access`, `frontend.routes`, `frontend.injection`, `frontend.client`, `frontend.config` | preflight (routes, code candidates); the advisories from `dependencies.security` |
| 4 **think** (= `finish`) | What matters, in which order? | verify jobs (run inside each batch, exists), summary tasks, the results overview, the orchestrator's closing message | everything |

A task with mixed checks goes where most of its checks belong (`backend.data`: mass assignment and data exposure are security; `tests.gaps`: missing authorization and security tests). The stage is a hint for ordering and for reporting, not a wall: a task can be started in any stage.

`stage` is one key per task in `audit.php` (`'stage' => 'security'`; default `standards`). `catalog` groups tasks by stage, `start --stage=security` starts every task of that stage that has not run and has no `skip_reason`, and `run.md` gets a stage column. Nothing else changes: report files, ID prefixes, required findings, the eval expected sets and the Boost skill all hang on the four audits, and a stage attribute gives the orchestrator the order without touching any of them.

### 3.4 What the orchestrator may decide

The skill names this decision space explicitly, because a weak model needs to be told what is allowed:

- **Scope:** which audits and tasks. Leave out what the project lacks (no browser tests, no frontend) and what `skip_reason` marks.
- **Order and batches** within a stage. The harness runs a batch at `max_concurrency`; the orchestrator does not split for that.
- **Rerun:** a `failed` task once. A `completed` task when its result is implausible (zero findings while the preflight shows material, e.g. advisories without a `vulnerable-dependency` finding). The harness caps attempts at `agent.max_attempts` (2); a third `start` returns an error, so a weak orchestrator cannot loop (G4).
- **Skills:** pass `task:skill,skill` or leave it to the harness (§4.1).
- **Budget:** `status` shows `spend_usd`; the orchestrator stops starting tasks when the user's budget is near and says so.
- **Gate:** failing tests → ask the user, `continue` only on a yes (exists).
- **Pause:** when `status` shows `paused`, tell the user the reason, the reset time if known and the resume command, then stop (§4.3).
- **Closing message:** after `finish`, read `run.md` and name the findings to fix first, the tasks that failed and why, and the cost.

### 3.5 The skill text

`resources/boost/skills/laravel-audit/SKILL.md` is rewritten for a weak model: at most 90 lines, in this order. Rules (five lines: only `audit:tool`, one command per call, never chain, never change files, `wait` on `busy`). The four stages as a table with one sentence each. A command cheat-sheet where every command names the `next` it leads to. The decision rules of §3.4. What to tell the user at the end. The current step list (open → catalog → preflight → gate → skills → batches → results → finish) becomes the stage table; nothing in it is lost.

Every `audit:tool` answer carries a `next` field with the command the harness would run now (`preflight routes composer tests`, `start --stage=analyse`, `wait`, `start --pending`, `finish`). A weak orchestrator that follows `next` blindly still produces a complete run; a strong one deviates when it sees a reason.

## 4. Harness changes

### 4.1 `audit:tool` additions

| Change | Where | Behaviour |
|---|---|---|
| `stage` per task | `Audits/AuditDefinition`, `AuditTask`, `catalog` | parsed from `audit.php`, default `standards`; `catalog.stages = {analyse: [keys], …}` |
| `start --stage=<name>`, `start --pending`, `start --failed` | new `Tool/TaskSelection` (resolves items and flags to task keys), used by `StartWork` | `--pending` = no stored outcome and not skipped; `--failed` = stored outcome with status failed |
| Attempt cap | `Tool/TaskStore` records `attempts` per key; `StartWork` refuses when ≥ `agent.max_attempts` (2) | error `attempts exhausted`, with the task's last reason |
| Automatic skills | `StartWork::assignSkills` falls back to `Skills/SkillSelector::select()` (cache or one call) for tasks without `:skills` | one decision less for the orchestrator; `agent.skills = none` switches it off |
| `wait` deltas | `Tool/Wait` keeps `tool/wait-cursor.json` | returns `done`, `finished_since` (key → status, findings, cost), `failed_since`, `running`, `pending`, `spend_usd`, `paused`, `next`; the full table stays in `status` |
| Spend and pause in `status` | `Tool/Status`, new `Tool/Spend` | `spend_usd` = sum of stored telemetry cost, `budget_usd`, `paused` block, `stages` progress (done / total per stage) |
| `next` everywhere | each action | one string, the harness's proposal |
| `finish` while paused | `Tool/Finish` | writes the reports of the finished tasks, run status `paused`, lists pending tasks under Limitations |
| Call budget | `ToolCommand` counts calls in `tool/calls.json` | after `tool.max_calls` (400) every action but `status` and `finish` returns an error: a loop guard for weak orchestrators |

### 4.2 One task store for both flows

`Run/Phases/RunAgents` passes `TaskStore::save(...)` as `onFinished` and loads earlier outcomes with `TaskStore::load()`, exactly as `Tool/Work` and `Tool/Finish` do today. The store directory is the same (`<run>/tool/tasks`). `php artisan audit --resume[=<run>]` reopens the latest run (or the given one), reuses its preflight in place, keeps every outcome with status `completed` and runs every other job again: pending ones, and failed ones, because the usual reason for a resume is an outage or a limit that also produced the failures. Then it finalizes. Verify jobs are stored too (exists), so a verified task is not verified again.

This closes F12 and is the basis for §4.3: a crash at task 30 costs the one running task, not the run.

### 4.3 Pause and resume

**Detection.** New `AgentExit::Limit`. A small `Agents/LimitSignals` class holds the patterns (`/usage limit|weekly usage limit|rate_limit_error|overloaded_error|credit balance|429/i`) and extracts a reset time when the text has one ("resets at …", "resets 2026-08-08 00:00 UTC"). `ClaudeCodeDriver::parse()` maps an error result whose text matches to `Limit`; `OpenCodeDriver::parse()` maps an `error` event with an `APIError` name and a matching message to `Limit`. The fixtures come from real CLI output captured once (F10 is **assumed** until then). `Limit` is not counted towards "two errors in a row, the model is not answering" (the uncommitted `MAX_ERRORS_IN_A_ROW` change on this branch), because it is a different situation with a different remedy.

**Runner.** `AgentRunner::run()` returns a `RunResult` (outcomes plus an optional `RunPause` with reason and `resetsAt`). On `Limit` it starts no further jobs and lets the running attempts end. The job that hit the limit gets no stored outcome, so it stays pending. Before starting any job it checks the run budget: `spend + estimate > agent.run_budget_usd` (null = no limit) pauses with reason `budget`; the estimate is the mean cost of the finished tasks of this run, zero before the first. Per-task budgets stay as they are (`agent.drivers.claude_code.max_budget_usd`, `AgentExit::Budget`).

**State.** `tool/pause.json`: `{reason, resets_at, pending: [keys], written_at}`. `run.md` and `run.json` get status `paused`. New `AuditExit::Paused`.

**Fixed flow.** `php artisan audit` prints "Usage limit reached (resets 14:00). 23 of 34 tasks done. Resume with `php artisan audit --resume=<run>`." Interactively it asks "Wait for the reset and continue? [y/N]". On yes it sleeps until `resets_at` with a countdown (when unknown: a one-turn probe every five minutes, `answerOnly`, until it succeeds), then continues the same run in the same process. On no, or without a terminal, it exits with `Paused`.

**`audit:tool`.** `status.paused = {reason, resets_at, pending, next: "start --pending"}`. `start` while paused for a limit whose reset is in the future returns an error with the reset time, so an orchestrator does not hammer the API. The first successful `start --pending` after the reset removes `pause.json`. `finish` is allowed while paused (§4.1).

**Orchestrator hosts.** Host A: the skill says what to tell the user. Host B: `audit:orchestrate` exits with `Paused` and prints the resume line; the orchestrator session itself may be the one that hit the plan limit, which the command detects the same way and reports.

### 4.4 Boost guidelines as the coding standard

New `Standards/GuidelineSource` (one class, one test file) reads, in this order: the `<laravel-boost-guidelines>` block of `AGENTS.md` (else `CLAUDE.md`) and splits it at `=== <name> rules ===`; `.ai/rules/**/*.md` without `index.md`; `.ai/guidelines/*.md`. It keeps the blocks named in `agent.standards.blocks` (default: everything except `foundation`, `boost`, `herd`, `deployments`, `tests` and `*laravel-audit*`, which are instructions to the coding agent, not standards for the code) and caps the total at `standards_max_chars` (6,000) with a note when cut.

`Prompts/PromptContextBuilder` renders the result once per run; `shared/task.md` gets a `{{standards}}` placeholder under a `## Coding standards` heading that is filled only for stage-2 tasks and `backend.conventions`. Prompt text: "Judge the code against these standards and the project's own conventions. A rule the project follows is not a finding. Group one finding per violated rule." The text is part of the prompt hash, so a changed guideline shows up in `run.md` like a changed prompt. `agent.standards` = `boost` (default), `none`, or a list of files.

`backend.conventions` also adopts Boost's own method (F11): "decisions, not defaults", at least three consistent examples before a convention counts, and the ten highest-signal forks from Boost's checklist (validation entry point, controller shape, where business logic lives, `$fillable` vs `$guarded`, accessor style, DTO style, action invocation, dependency acquisition, helper vs facade, enum style) replace the current free-form checklist in `tasks/conventions.md`.

Why not fetch guidelines through the Boost MCP: the text is static, small and already on disk; reading it once deterministically costs nothing per turn, and it works for OpenCode and local models the same way.

### 4.5 Weak-model fixes (from the review of run 2026-09-24, F14)

Each one is small, has a test from the review and lands in phase 5:

1. **Overview with findings** (`Run/TaskRequests::results()`, `shared/results.md`): the digest per audit is the count line once plus a table `ID · Severity · Confidence · Check · Title`, sorted by severity then confidence, capped at 25 rows with a "+N more low/info" line. The prompt says to state in one sentence when there is no cross-audit pattern instead of speculating.
2. **Generated summary names medium findings** (`Reports/GeneratedSummary`): when nothing is `high` or above, the three most important `medium` findings are named as `ID — Title`.
3. **Verify by category** (`Reports/VerifyPolicy`, `TaskSettings`): `agent.verify.threshold` (2) and `agent.verify.always_categories` (`security`, `correctness`): a finding of those categories with severity ≥ `medium` and confidence ≠ `confirmed` is always verified. Values injected, no config access in the policy.
4. **Per-task `max_turns`** (`audit.php`, `AgentRequest`, both drivers): `backend.dead-code` 30, `backend.database` 25. OpenCode gets one generated agent per distinct `steps` value (`laravel-audit-30`), because `steps` is per agent in its config. The dead-code prompt gets a few-turns strategy: one search over all class names of a folder, then only the unreferenced ones individually.
5. **Project context and runtime facts** (`project.context` in `config/audit.php`, `about.json` facts: DB, queue, cache and session driver, versions) under `## Project context` in `task.md` and `verify.md`, with the rule "rate exploitability against the project context; a single-user app makes a finding that needs another logged-in user at most `low` unless it is reachable without login" and "name drivers only as given in Runtime facts".
6. **Linter warnings into the repair prompt**, and deterministic truncation of titles above 120 characters at a word boundary.
7. **ID renumbering after repair**: a test that two tasks both returning `BE-001` end up as `BE-001` and `BE-002`.
8. **Local-model notes** in `config/audit.php` and the README's OpenCode section: `max_concurrency` 1, a longer `AUDIT_TIMEOUT`, verify on, `project.context` set.

Optional, measured first (phase 7): a `symbols` preflight snapshot that counts references to every class under `app/` once, so `backend.dead-code`, the most expensive task (F3), reads a table instead of searching.

### 4.6 Report quality

Observed in `backend.md` of run 2026-09-24 (Opus 5.5, 22 findings). The findings themselves are mostly sound; what makes the report hard to use is everything around them. Every fix below is deterministic harness work on fixture reports, costs no model tokens and lands in phase 5.

| # | Defect in the report | Cause | Fix |
|---|---|---|---|
| Q1 | `## Summary` reads "22 findings from 12 tasks (5 medium, 16 low, 1 info)." and nothing else. | `GeneratedSummary` names titles only from `high` | §4.5 item 2, plus one sentence per stage ("Security: BE-016, BE-017, BE-018 …") |
| Q2 | `## Limitations` has 34 bullets. "All preflight steps finished ok" appears four times. Task-local IDs survive: "That is why BE-001 is rated likely" means BE-004, "BE-001 assumes the defaults" means BE-013, "impact in BE-001" means BE-022. | `AuditAssembler` concatenates every task's bullets; `FindingRenumberer` remaps IDs only inside `## Findings` | apply the task's ID map to every section the task wrote; drop bullets that only say the preflight succeeded; deduplicate by normalized text; at most three bullets per task under the task title; the rest goes to `logs/<task>.limitations.md` |
| Q3 | `### Out of scope observations` has 12 bullets; several repeat existing findings (inline validation, return types, the SSRF `authorize()`), others cite snapshot IDs (R-01, K-02) the reader cannot resolve. | no cross-check against the findings; prompts allow IDs in prose | drop an observation whose file or route is a location of an existing finding; cap at five per audit; prompt rule "name files and routes in prose, never snapshot IDs" |
| Q4 | Findings are numbered in task order: BE-001 is "four actions use `__invoke`", the SSRF is BE-016. | `AuditAssembler` numbers while iterating tasks | order by severity, then confidence, then task; renumber; `Related` and prose follow the map |
| Q5 | Nothing tells the reader which checks ran and came out clean. | not rendered | new section `## Checks` per audit: check · task · result (`N findings`, `clean`, `not audited: <reason>`, `skipped: <reason>`), built from the task outcomes; `report-format.md` gets the section |
| Q6 | Low-value findings dilute the top: 4 of 140 actions use `__invoke` (BE-001), exception suffixes (BE-002). | the task reports every deviation from the dominant pattern | the standards block (§4.4) and Boost's "decisions, not defaults" rule in the conventions task; Q4 moves `info` and `low` to the end |
| Q7 | BE-010 says "SQLite file"; BE-022 knows the app runs on PostgreSQL. | runtime facts missing from the prompt | §4.5 item 5 |
| Q8 | BE-016 and BE-017 are `medium` because of "any logged-in user", in a single-user app. | threat model unknown | §4.5 item 5, verify always for security |
| Q9 | `## Dead code candidates` has one row; the task "searched about 50 of more than 100". | turn cap hit | §4.5 item 4; later the symbols snapshot |
| Q10 | First person throughout ("I sampled", "I didn't check"). | prompt allows it | one line in `task.md`: impersonal, no "I" |
| Q11 | Linter warnings stay warnings: check `other` on BE-003, two titles over 120 characters. | no feedback loop | §4.5 item 6 |
| Q12 | The overview in `run.md` says it cannot name findings. | counts only | §4.5 item 1 |

The checklist for G2, each line a test on the assembled report: the summary names at least three findings by ID; every `XX-nnn` in the body is a heading in the same report; findings are sorted by severity rank; at most three limitation bullets per task and no two equal after normalization; a `## Checks` section with one row per check of the audit; no line starts with "I "; no snapshot ID pattern (`[A-Z]-\d{2}`) in prose.

## 5. Low-tier models

### 5.1 Target matrix

| Model | Host | Price | Role in MVP3 |
|---|---|---|---|
| Claude Haiku 5.5 (`claude-haiku-5-5`) | Claude Code | $0.10 / $0.50 per MTok | **the only model of MVP3's acceptance runs** (G1, G2); thinking on by default |
| Claude Opus 5.5 | Claude Code | $4 / $20 | reference baseline for one `audit:eval` comparison (G5) |
| Qwen 3.8 27B, Gemma 4 31B (`codebar/…`), DeepSeek 4 Flash | OpenCode | self-hosted or hosted | the user's later daily drivers; same code paths, **not verified in MVP3** (user decision 2026-10-18). Qwen answers through OpenCode (F9); DeepSeek is not configured yet |

Config for the test setup: `AUDIT_AGENT=claude_code AUDIT_MODEL=claude-haiku-5-5 AUDIT_CONCURRENCY=2`.

### 5.2 What weak models get wrong and what catches it

| Failure | Caught by | Status |
|---|---|---|
| Template drift (wrong heading, decorated values) | `ReportNormalizer`, one repair pass | exists |
| Findings about files it never opened | one-turn check `AgentRunner::unread()` → open-files follow-up | exists |
| Invented paths, quotes that are not in the file | `EvidenceChecker`, `FindingRejector` | exists |
| Searching class by class until the turn limit | per-task `max_turns`, the few-turns strategy, answer-now follow-up, later the `symbols` snapshot | §4.5 |
| Severity too high (threat model unknown) | project context, verify always for security and correctness | §4.5 |
| Wrong database or driver named | runtime facts in the prompt | §4.5 |
| Skill selection answers junk | validation, fallback to no skills | exists |
| Orchestrator forgets where it is | `next` hints, `wait` deltas, `start --pending`, `status.stages` | §4.1 |
| Orchestrator reruns forever | attempt cap, call budget | §4.1 |
| Orchestrator chains commands | the skill's rule plus the permission matcher of the CLI (verify) | §3.2 |
| Model does not answer at all (overloaded local server) | two errors in a row stop the batch (uncommitted change on this branch); empty replies fail the task | on branch |

### 5.3 Tokens per turn on local models

The OpenCode probe (F9) shows 15.4k input tokens for a one-line prompt before our task prompt is added. A 15-turn task on a local model therefore re-sends roughly 20k tokens per turn, 300k per task, which is where the minutes go. Levers, in order: fewer turns (per-task caps, candidates and symbols snapshots), the restricted tool list of the `laravel-audit` agent (exists; **open** whether OpenCode trims the schemas it sends to the agent's `tools`), `--pure` (exists), `max_concurrency` 1. Recorded here for the later local-model work; MVP3 does not measure it.

## 6. Efficiency and cost

The user's reference point is about $100 per project audit with large models. The numbers below come from F2, F7 and F8.

| Lever | Effect | Basis |
|---|---|---|
| **Model** | Backend run $6.84 on Opus 5.5 → ≈ $0.17 on Haiku 5.5 at equal tokens. A full run of 70 jobs ≈ $20–25 on Opus 5.5 → ≈ $0.60 on Haiku; ≈ $1.20 if Haiku needs twice the turns. | F2, F7: 40× |
| **Turn caps per task** | The two runaway tasks were 42 % of the run; a cap of 25–30 with a strategy line keeps them in the band of the other tasks. | F3; expected −20–30 % |
| **Verify only where it pays** | security and correctness findings are verified, consistency and style are not; `downgrade` is cheap. | §4.5 |
| **Orchestrator overhead** | Each `audit:tool` answer ≤ 2 KB, `wait` returns deltas; about 100 calls per run ≈ 40k tokens of cumulative context. Claude Code re-sends the context every turn: 100 turns × ~25k ≈ 2.5M cache-read tokens ≈ $0.03 on Haiku, ≈ $1 on Opus. The orchestrator therefore runs on the cheap model too. | F8, §4.1 |
| **Prompt size** | 11.5–16.4 KB per task today; the standards block adds up to 6 KB to stage-2 tasks only. No other growth. | F6 |
| **Guardrails** | `agent.run_budget_usd` pauses the run; per-task `max_budget_usd` fails one task; plan limits pause and resume. Nobody spends a plan's week on one audit by accident. | §4.3 |

Target for G1: the full e2e run on Haiku 5.5 ≤ $1.50 including the orchestrator. Target for a subscription user: one full run per day on a Max plan without reaching the five-hour limit (**assumed**, measured in phase 8).

## 7. Boost integration

- **Skill** `laravel-audit`: rewritten per §3.5; the guideline `core.blade.php` stays at five lines and adds one sentence: "or run `php artisan audit:orchestrate` to let a headless agent drive the audit".
- **Guidelines as standards:** §4.4.
- **Skills per task:** the harness's automatic selection applies in both flows (§4.1); the orchestrator can override per task. Task agents keep getting skill text inlined, because their `Skill` tool stays off (MVP2 F5–F7).
- **Boost MCP tools** for task agents stay as configured (`agent.boost_tools`). Per-task tool lists stay deferred (MVP2 §11).
- **`audit:install`** already registers skill and guideline; nothing new.

## 8. Maintainability

- **File size:** `tests/Core/FileSizeTest` fails for any `src/` file above 250 lines. It starts with an allowlist of the six files in F13, and each phase that touches one removes it from the list. `AgentRunner` is split first (§9, phase 0): `AgentRunner` keeps the loop, `Agents/FollowUp` decides what a result leads to (repair, answer-now, open-files, outcome), `Agents/JobQueue` holds critical-first ordering and readiness.
- **One class per `audit:tool` action** (exists) and per new concern: `TaskSelection`, `Spend`, `LimitSignals`, `GuidelineSource`, `OrchestratorPrompt`, `OrchestratorSession`.
- **TDD:** every phase in §9 names its tests first; red, green, then refactor; `composer test`, `composer analyse` and `composer format` green per commit. New console text goes into both `resources/lang/en` and `de` (the catalog has 163 keys each).
- **KISS decisions:** state is JSON files in the run folder; stage is an attribute, not a new audit; the headless host contains no flow logic; limit patterns live in one class; no retries inside drivers; nothing new in `composer.json`.

## 9. Work order

| Phase | Tests first | Then | Done when |
|---|---|---|---|
| 0 Characterization and split | `AgentRunnerTest` already pins `run()`'s result shape, the critical-first order and the follow-ups; `FileSizeTest` with the allowlist | split `AgentRunner` into runner, `FollowUp`, `JobQueue`; the public signature stays (`RunResult` comes with the pause in phase 3) | all tests green, `AgentRunner` ≤ 150 lines, allowlist empty |
| 1 Stages and primitives | `AuditDefinitionTest`: `stage` parsed, default `standards`, unknown value rejected; `ToolTest`: `catalog.stages`, `start --stage`, `--pending`, `--failed`, third attempt refused, automatic skills, `wait` deltas, `spend_usd`, `next` in every answer, call budget | `TaskSelection`, `Spend`, `TaskStore::attempts`, `Wait` cursor, `stage` in all four `audit.php` | the Tool tests describe a complete stage-by-stage run |
| 2 One task store, resume | `AuditCommandTest`: a fake agent fails the process at task 3 of 4; `--resume` runs only task 3 and 4; reports equal an uninterrupted run; `--resume` without a run says so | `RunAgents` saves and loads through `TaskStore`; `--resume` option | G3 for the crash case |
| 3 Pause | driver tests with limit fixtures → `AgentExit::Limit` and `resetsAt`; runner test: no new job after a limit, `RunPause` returned, budget pause with the mean estimate; command test: message, exit code, `pause.json`; Tool test: `status.paused`, `start` refused before reset, `finish` while paused | `LimitSignals`, `RunResult`/`RunPause`, `AuditExit::Paused`, the interactive wait, `Tool/Pause` | G3 for limit and budget |
| 4 Standards from Boost | `GuidelineSourceTest` with a fixture `AGENTS.md`: the right blocks, the exclusions, `.ai/rules`, the cap, nothing without Boost; `AuditTest`: `{{standards}}` only in stage-2 tasks and conventions, hash changes | `GuidelineSource`, placeholder, config, conventions prompt per §4.4 | a fixture run shows the standards block in the right prompts |
| 5 Weak-model fixes and report quality | the acceptance tests listed in `improvement-prompt.md` for tasks 1–7; `AuditAssemblerTest`: severity ordering and renumbering, the ID map applied to prose, limitation dedupe and cap, the out-of-scope cross-check, the `## Checks` section; the G2 checklist as one test over a fixture report | §4.5 items 1–8, §4.6 Q1–Q6 and Q10 | tests green; the assembled fixture report passes the G2 checklist |
| 6 Headless orchestrator | `ClaudeCodeDriverTest`/`OpenCodeDriverTest`: orchestrator command lines and the OpenCode agent entry; `OrchestrateCommandTest`: prompt contains the run ID and the skill body, `orchestrator.md` saved, `Finish` called when the agent did not, exit codes | `OrchestrateCommand`, `OrchestratorPrompt`, `OrchestratorSession`, skill rewrite, guideline sentence | a fake agent that only emits `audit:tool` calls completes a run |
| 7 Optional: symbols snapshot | `CondenserTest` for the reference counts on the fixture project | `Preflight/Condensers/SymbolUsage`, snapshot, dead-code prompt | measured turn and cost drop on homemanager `backend.dead-code` |
| 8 Verification and docs | – | the runs of §10; README, CHANGELOG, `config/audit.php` comments; §12 filled | G1, G2, G5 numbers recorded |

Phases 2 and 3 come before 6 on purpose: the first unattended Haiku runs on the user's account will meet the plan limit, and they must leave resumable runs behind.

## 10. Verification plan

1. **Package tests:** Pest, PHPStan level 6, Pint, the arch tests, per commit.
2. **Fixture eval:** `build/e2e` gets an `expected.json` with at least eight planted findings across the four audits and four negative controls (there is none yet; the `build/tests/eval-*` folders are test temp dirs). `php artisan audit:eval build/e2e/expected.json --runs=2` once on Haiku 5.5 and once on Opus 5.5 gives recall, false alarms, stability and cost (G5).
3. **Real runs on the e2e app, Claude Code with Haiku 5.5 only:** (a) `php artisan audit --model=claude-haiku-5-5` fixed flow; (b) `php artisan audit:orchestrate --model=claude-haiku-5-5` (G1); (c) a pause drill: `AUDIT_RUN_BUDGET_USD=0.20` pauses the Haiku run mid-way, `--resume` finishes it, the reports are compared with (a) (G3).
4. **Reading the output:** the G2 checklist of §4.6 runs over every assembled report; five findings per run are checked by hand against the code; rejected findings and failed tasks are read in `run.md`. The orchestrator's `orchestrator.md` is compared with `run.md`: does it name the same top findings?
5. **Homemanager:** the backend run is repeated on Haiku 5.5 and compared with the Opus 5.5 run of 2026-09-24 in findings, turns, cost and duration.

## 11. Config at a glance (new keys)

| Key | Default | Env | Purpose |
|---|---|---|---|
| `agent.max_attempts` | 2 | – | starts per task before `audit:tool` refuses |
| `agent.run_budget_usd` | null | `AUDIT_RUN_BUDGET_USD` | pause the run when the next task would exceed it |
| `agent.verify.threshold` | 2 | – | severity rank − confidence rank that triggers verify |
| `agent.verify.always_categories` | `security`, `correctness` | – | always verify from `medium` unless `confirmed` |
| `agent.standards` | `boost` | – | `boost`, `none` or a list of files |
| `agent.standards.blocks` | all but tooling blocks | – | which Boost rule blocks count as standards |
| `standards_max_chars` | 6000 | – | cap for the inlined standards |
| `agent.orchestrator.model` | null (task model) | `AUDIT_ORCHESTRATOR_MODEL` | model of the headless orchestrator |
| `agent.orchestrator.max_turns` | 300 | – | turn cap of the headless orchestrator session |
| `agent.orchestrator.max_budget_usd` | null | – | budget of the orchestrator session itself (Claude Code) |
| `tool.max_calls` | 400 | – | `audit:tool` calls per run before only `status` and `finish` work |
| `project.context` | null | – | free text about the app's threat model and runtime |
| `tasks.<key>.max_turns` (in `audit.php`) | `agent.max_turns` | – | per-task turn cap |

`agent.timeout` gets `AUDIT_TIMEOUT` (it has no env variable today). Legacy keys are not affected.

## 12. Measurements (filled in phase 8)

| Run | Model | Tasks completed / failed | Findings (C/H/M/L/I) | Rejected | Recall | Turns | Cost USD | Duration | Orchestrator calls / cost |
|---|---|---|---|---|---|---|---|---|---|
| e2e fixed | claude-haiku-5-5 | | | | | | | | – |
| e2e orchestrated | claude-haiku-5-5 | | | | | | | | |
| e2e pause drill + resume | claude-haiku-5-5 | | | | | | | | – |
| homemanager backend | claude-haiku-5-5 | | | | | | | | – |
| homemanager backend (2026-09-24) | claude-opus-5-5 | 12 / 0 | 0/0/5/16/1 | 0 | – | 250 | 6.84 | 11.7 min | – |

## 13. Implementation status (2026-10-18, branch `refactor/orchestrator`)

- **Phase 0 done.** `tests/Core/FileSizeTest` caps source files at 250 lines with an empty allowlist. `AgentRunner` (263 lines) is split into `AgentRunner` (127, the loop), `Agents/JobQueue` (114, critical-first order and readiness) and `Agents/FollowUp` (90, the repair, answer-now and open-files decisions). The public signature of `run()` is unchanged; the 45 agent tests are the characterization. The "Nothing to do" outcome still bypasses `onFinished`, as before; worth a look in phase 2 when both flows share the store.
- **Phase 1 done.** `Enums/Stage`, `stage` per task in the four `audit.php` files (10 analyse, 14 standards, 10 security, asserted by a test), `Tool/TaskSelection` (`--stage`, `--pending`, `--failed`), `Tool/Attempts` (`agent.max_attempts`, 2), `Tool/NextStep` (`next` in catalog, status, wait, preflight and start; busy worker → `wait`, no preflight → `preflight <steps>`, gate aborted → ask the user, pending stage → `start --stage=…`, failed with attempts left → `start --failed`, else `finish`), `Tool/CallBudget` (`tool.max_calls`, 400), `wait` deltas with a cursor at the latest `finished_at`, `status` with `stages`, `pending` and `spend_usd`, automatic skill selection in `start` for tasks the session did not assign (one call per run, cached in `skills.json`). 360 tests, PHPStan level 6 and Pint pass.
- **Phase 2 done.** `RunAgents` saves every outcome to `TaskStore::directoryFor($run)` (`<run>/tool/tasks`) as it finishes and loads the stored ones first; `Tool/Finish` uses the same path without its own closure. `php artisan audit --resume[=<run>]` (`Run/Phases/ResumeRun`) finds the run through `PreflightReuse`, takes the audits from `run.json`, keeps completed outcomes and reruns the rest; a test fails two tasks with an API error, resumes and checks that the completed task did not run again.
- **Not yet started:** phases 3–8. The skill text (§3.5) still describes the MVP2 step list; it is rewritten in phase 6 together with the headless host.

## 14. Decisions, assumptions, open questions

**Decided (2026-10-18)**

- Stages are an attribute of tasks, not a new cut of the audits (§3.3).
- The orchestrator is the skill text; the headless host is thin and reuses `audit:tool` (§3.2). The fixed flow `php artisan audit` stays as the deterministic path and as the basis of `audit:eval`.
- Claude Haiku 5.5 is the test model; it replaces the "Haiku 3" of the brief because Claude Code's current model list has no Haiku 3 and 5.5 is cheaper (F7, F8).
- Acceptance runs use Claude Code only. OpenCode stays a driver and shares every code path, but nothing in MVP3 is verified against it (user decision 2026-10-18).
- Report quality is a goal of its own (G2, §4.6), because the last run's report was hard to use even on Opus 5.5.
- The orchestrator never reads code and never fans out.
- Both flows persist every task outcome on disk as it finishes.
- The eight review tasks of 2026-09-24 are part of MVP3 (§4.5).
- Documents stay in English, like MVP2 and the code.

**Assumed, to verify in the named phase**

- Claude Code reports a plan or rate limit in `-p --output-format json` as `is_error: true` with the message in `result` (phase 3, capture a real fixture).
- Claude Code's `Bash(php artisan audit:tool:*)` permission and OpenCode's `permission.bash` pattern deny chained commands (phase 6, one experiment each, as MVP2 §6.2 asked).
- `--restricted` works for the orchestrator host while Bash is allowed (phase 6).
- OpenCode sends only the agent's `tools` schemas per turn (phase 8, measure).
- A full e2e run on Haiku 5.5 stays under $1.50 and under a Max plan's five-hour limit (phase 8).

**Open**

- DeepSeek 4 Flash: provider entry and model name in `opencode.jsonc`.
- Whether `summary => agent` should stay off by default for weak models (current plan: yes, off; the generated summary improves in §4.5).
- Whether the `symbols` snapshot (phase 7) pays for itself; decided by the measured cost of `backend.dead-code` after phase 5.
