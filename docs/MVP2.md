# astro2006/laravel-audit – MVP2: Boost skills, agent-driven orchestration, leaner harness

> Status: concept v1 · 2026-09-23 · Basis: `MVP1.md`, the package at `992c6a4` (branch `feature/new-prompts`), the full review of 2026-09-23
>
> This document turns the six-step brief ("Analyze the tests first … Parallel execution") into a concept that can be executed step by step. Every claim about Boost, Claude Code or OpenCode below was verified against the installed versions (Boost 2.9.1, Claude Code 2.1.280, OpenCode 1.18.32) or is marked **assumed** or **open**.

## 0. Summary

The audit already does most of what the brief asks for: tools run first, the model sees a compact snapshot, and every ID has its own detail file that the agent opens on demand. What is missing:

1. **Skills are structurally unreachable.** The package itself switches them off in both drivers, and Claude Code's `--restricted` mode hides project skills anyway. Fix: the harness discovers the installed skills, one model call per run (or the orchestrating agent) assigns them to tasks, and the harness inlines the chosen text into each task prompt. No per-task tool call, nothing hardcoded, identical for every driver.
2. **The orchestrator is a second, half-finished harness.** The headless OpenCode orchestrator (`orchestrator => agent`, `_orchestrator.md`, `RunOrchestrator`) duplicates the fixed flow and has lock and exit-code bugs. Fix: describe the orchestration in a Boost skill that the user's *own* Claude Code or OpenCode session loads, and let it drive `php artisan audit:tool`. The fixed flow (`php artisan audit`) stays as the deterministic path for CI and for people who do not want to babysit an agent.
3. **Tokens are spent per turn, not per prompt.** In the baseline run a one-turn task costs ~9k input tokens; a 9-turn task costs 157k and a 17-turn task 452k, because the whole context is resent every turn. Prompt trimming helps ~15%; fewer turns help 5–10×. Both are in scope, in that order of impact.
4. **The tests protect parsing and condensing well, and the orchestration path and drivers badly.** Eleven characterization tests are needed before the refactor; they are listed in §1.

Priorities from the brief (resources > speed > readability) decide every trade-off below.

## 1. Test coverage report

Basis: 299 tests, all green; names taken from the suite, not from coverage tooling (no coverage run yet: **open** whether to add pcov to CI).

### 1.1 Covered

| Area | Tests | Quality |
|---|---|---|
| Preflight ordering (tests first, light steps, browser, phpstan), step selection per audit, crash → error result, gate decision, summary round-trip | `PreflightRunnerTest` (7) | Good, but every async step is faked synchronously; `AsyncStep::start`/`finish` with a real background process is never exercised |
| Individual steps: about, pint, routes, translations, phpstan, composer, browser skip paths, tests in background, coverage only for tests audit, Herd, DB guard refusal | `StepsTest` (16) | Happy path plus skip paths; no failing-tool JSON, no timeout |
| Condensers: junit, broken environment, route facts, coverage, route→test map, translations, phpstan grouping, usage map, dependency facts | `CondenserTest` (13), `CodeCandidatesTest`, `MigrationSchemaTest` | Good for the fixtures; `CodeCandidates` covers 4 of 13 patterns, `MigrationSchema` only well-formed 4-space files |
| Database guard | `DatabaseGuardTest` (8) | One test encodes the wrong rule (`.env.testing` used whenever it exists, see review) |
| Snapshots (metrics, IDs, caps, `_all.md`, detail files) | `SnapshotTest` (8) | Good |
| Report pipeline: extractor, normalizer, parser, linter, evidence checker, editor, keys, overlaps, locations, verify policy, assembler | `tests/Reports/*` (≈55) | Good; missing: `VerdictApplier`, `FindingRenumberer` edge cases, `Location` failure modes, German assembler output |
| Agent runner: repair, turn limit, one-turn follow-up, dependencies, cycles, concurrency 1, critical-first, poll reporting | `AgentRunnerTest` (13) | Good |
| Drivers: command lines, config files, JSON parsing, resume | `ClaudeCodeDriverTest` (10), `OpenCodeDriverTest` (6), `CodexDriverTest` (4), `DriverRegistryTest` (7) | Command lines yes; **Codex resume flags not asserted** (drops `--sandbox read-only`), OpenCode orchestrator bash pattern asserted but not its chaining semantics |
| Audits and prompts: ownership, required findings, skip rules, placeholder rendering, hashes, published layout | `AgentTest` (22), `AuditDefinitionTest` (3) | Good |
| End to end via the command: preflight only, full audit with fake agent, required findings, reuse preflight, `--without`, production guard, German, Codex refusal, single/parallel mode, gate ask and `--force` | `AuditCommandTest` (18) | Good for the fixed flow. "Continue with failures?" **is** covered (`asks at the test gate once the preflight is done and stops when told to`) |
| Agent orchestrator | `OrchestrationTest` (7) | Only the `--sync` path; no background worker, no lock, no timeout |
| Install | `InstallTest` (11) | Planning and one execution; no Boost hand-off |
| Config, child environment, project inspector, console board, eval report, house rules | 20 | Fine |

### 1.2 Not covered

- Async preflight: a real `Process::start` step, stdout larger than the pipe buffer, timeout while a light step runs.
- Worker path of `audit:tool`: `nohup` launch, `WorkerLock` staleness and double acquire, `Wait` timing, torn reads of `summary.json`, orchestrator exit codes when nothing was started.
- Driver edge cases: `AgentExit::Timeout` and `stop()`, stderr in the error, dotenv removal in the spawned environment (only `AUDIT_TASK` is asserted), Codex resume flags, OpenCode `reason: length`, `ClaudeUsage` fallbacks.
- Report edge cases: en-dash ranges and `#L12` in Locations, lines containing `✅` in Evidence, bold field names, duplicate table rows, non-English `### Out of scope observations`.
- Boost resources: nothing, because nothing exists yet (no `resources/boost/`, no test that every task names an existing skill, no test that inlined skills stay under the size cap).
- Install: Boost hand-off, non-interactive default, missing Boost.
- Eval: `EvalRunner` and `EvalCommand` wiring.

### 1.3 Weak tests

- `DatabaseGuardTest::accepts a separate database from .env.testing` asserts behaviour Laravel does not have (see §4.4).
- `ChildEnvironmentTest` has one case and does not cover a variable that is both exported and in `.env`.
- `OpenCodeDriverTest::gives the orchestrator a shell that runs audit:tool only` asserts the pattern string, not what the pattern lets through.
- `OrchestrationTest::lets the orchestrator agent pick the steps and tasks` fakes the agent and runs the worker inline, so it cannot fail on the bugs the worker really has.

### 1.4 Verdict and tests to add before refactoring

Sufficient for `Reports/`, `Preflight/Condensers/`, `Snapshots/`, `Prompts/`. **Not sufficient** for `Agents/Drivers/`, `Orchestration/`, `Preflight/PreflightRunner` (async) and `Install/`. Add these characterization tests first; each pins current external behaviour so the refactor can move code freely:

1. `PreflightRunner` with a real async step that writes 200 KB to stdout and finishes after the light steps.
2. `WorkerLock`: acquire twice, stale lock after 30 s without a pid, release on exception.
3. `audit:tool` background mode with a fake `php` binary: `start`, `wait` until `done`, `result`.
4. `RunOrchestrator`/skill flow: worker still running when the session ends → run waits or fails, never exits 0 with empty reports.
5. `CodexDriver::resumeCommand` keeps `--sandbox read-only`.
6. `AgentProcesses` timeout → `AgentExit::Timeout`, process stopped, stderr in the message.
7. `Location::parse` with `app/X.php:12–14`, `app/X.php#L12`, `app/X.php:12, 40`.
8. `EvidenceChecker` with a `✅` line in the quoted code.
9. `ChildEnvironment` with a variable exported *and* present in `.env`.
10. Prompt token budget: every rendered task prompt (fixture project) stays under a fixed byte budget; the test fails when a prompt grows.
11. Skill selection: an answer with unknown task keys, unknown skill names or more than `skills_per_task` entries is rejected and the run continues without skills and with a warning; a valid answer ends up inlined in exactly the tasks it names.

## 2. Skill diagnosis

### 2.1 Facts

| # | Claim | Evidence |
|---|---|---|
| F1 | Boost discovers third-party skills and guidelines only from **direct** dependencies, under `<package>/resources/boost/skills/<name>/SKILL.md` and `<package>/resources/boost/guidelines/*.blade.php\|.md` | `vendor/laravel/boost/src/Install/ThirdPartyPackage.php:70-80`, `Support/PackageRegistry.php:82-91` |
| F2 | Skills are copied verbatim into every configured agent's skill directory: Claude Code `.claude/skills/<name>`, OpenCode `.agents/skills/<name>`; key is the frontmatter `name`, **no package prefix** | `Install/Agents/ClaudeCode.php:62`, `Install/Agents/OpenCode.php:103`, `SkillComposer.php:205-214`; homemanager has identical copies in both directories |
| F3 | Third-party guidelines are inlined into `CLAUDE.md`/`AGENTS.md` as `=== <package>/<file> rules ===` blocks, so they are always loaded | `GuidelineComposer.php:268-289`; homemanager `CLAUDE.md` is 12.3 KB with 10 blocks |
| F4 | A package is only included after the user selects it; `boost:update --discover` prompts, but **skips discovery when non-interactive or under Composer** | `UpdateCommand.php:69-80`, `runningAsComposerScript()` |
| F5 | The Claude Code task driver passes `--tools Read,Grep,Glob`; the `Skill` tool is a built-in tool and is therefore **removed** | `src/Agents/Drivers/ClaudeCodeDriver.php:95`; probe: `claude -p --restricted --tools Read,Grep,Glob` answers "Glob, Grep, Read", with `…,Skill` it lists 13 skills |
| F6 | With `--restricted`, Claude Code loads **neither** project skills from `.claude/skills` **nor** `CLAUDE.md`: 13 built-in skills, "no" to the Boost block, 5.9k cached context tokens. Without `--restricted`: 49 project skills, "yes", 15.6k tokens | two probes on homemanager, 2026-09-23 |
| F7 | The OpenCode task agent config sets `tools: {'*': false, read, glob, list}` and denies the `skill` permission | `src/Agents/Drivers/OpenCodeConfig.php:14` (`DENIED_PERMISSIONS` contains `skill`), `:97-135` |
| F8 | OpenCode reads skills from `.opencode/skills`, `.claude/skills` and `.agents/skills`; the model loads one with the native `skill` tool; `permission.skill` can allow single names | opencode.ai/docs/skills |
| F9 | OpenCode always loads `AGENTS.md` (then `CLAUDE.md`); there is no option to switch that off | opencode.ai/docs/rules |
| F10 | No task prompt names a skill. The only Boost references are `{{boost_note}}` (MCP tools) and `database.md` (`database-schema`) | `grep -rni skill resources/prompts` → 0 hits |
| F11 | The one-turn baseline costs ~9k input tokens per task (prompt 8.5–11.8 KB ≈ 3k tokens, `AGENTS.md` ≈ 3k, tool schemas and system prompt ≈ 3k) | `homemanager/storage/audit/2026-09-22_09-34-36/run.json`, `logs/*.prompt.md` |

### 2.2 Diagnosis

Skills are never used because **the harness makes them impossible, by design of the security model, in both drivers** (F5, F6, F7), and because **no prompt asks for one** (F10). Boost's side is fine: the skills are installed where both CLIs look (F2, F8). Two of the three checks in the brief therefore pass (installed, visible on disk) and the third fails twice (the tool is gone, the prompt is silent).

Enabling the tool is the wrong fix for priority 1: dropping `--restricted` adds ~10k tokens to *every turn* of every task (F6), and OpenCode would list all 49 skill descriptions per turn. A 15-turn task would pay 150k extra tokens for a skill it may never load.

### 2.3 Fix: discover once, select once per run, inline per task

The skill set differs per project (homemanager has 49, a fresh app has Boost's ~10, a project without Boost has none), so no list in the package can name them. The design has three parts; only the middle one involves a model.

**1. Inventory (harness, deterministic).** At run start `Prompts/SkillInventory` scans `.claude/skills`, `.agents/skills`, `.opencode/skills` (where Boost installs them, F2/F8) and the package's own `resources/boost/skills`. For every `SKILL.md` it reads only the frontmatter `name` and `description` (Boost requires both, ≤ 1 024 chars). The inventory goes to `preflight/skills.json` and into the requirements block of the console ("Skills: 49 found").

**2. Selection (one model call per run, or the orchestrating agent).** This is what automatic skill selection does inside a CLI: the model reads all descriptions and picks. The CLIs do it on *every turn of every task*; the concept does it **once per run**:

- Fixed flow (`php artisan audit`): one short agent call before the tasks start. Input: the task list with each task's title and check descriptions (already in `audit.php`) and the inventory's names and descriptions. Output: strict JSON `{ "backend.conventions": ["php", "models"], "tests.phpstan": ["phpstan"], … }`, at most `skills_per_task` (default 2) per task. Cost on homemanager: ≈ 49 × 150 chars descriptions + 28 tasks ≈ 4k input tokens, ~400 output, once. The answer is validated (known task keys, known skill names, cap) and written to `preflight/skills.json`; an invalid answer falls back to no skills plus a warning, never to a retry loop. `agent.skills` = `auto` (default), `none`, or a fixed map for projects that want control.
- Skill-driven flow (§5.2): `catalog` prints the inventory next to the tasks; the orchestrating agent decides and passes `start backend.conventions --skills=php,models`. Same validation, same file.
- Task agents never select. They have no `Skill` tool (F5, F7) and would pay all descriptions per turn.

**3. Inlining (harness, deterministic).** `Prompts/SkillLoader` strips the frontmatter and inlines each selected body under `## Skills` in the task prompt, capped by `skill_max_chars` (default 2 000 per skill) with a `Limitations` note when cut. The prompt says: "Apply the skills below; they are this project's conventions. Do not look for other skills." Inlined text is part of the prompt hash, so `run.md` and `audit:eval` see skill changes like prompt changes. The drivers stay as they are.

Why one call and not keywords: descriptions are free text written by package authors; a keyword table in `audit.php` would be the hardcoding the brief rejects, just one level up. Why not per task: 28 calls with 2k tokens of descriptions each would cost more than the tasks they serve on a 27B model. Why validate strictly: on `qwen3.x-27b`/`qwen3-coder:30b` a one-shot JSON answer is reliable when the schema is tiny and the fallback is "no skills", so the run never depends on it.

## 3. Folder structure

### 3.1 Current

```
resources/
├── audits/{backend,frontend,tests,dependencies}.php     tasks + checks
├── prompts/agents/
│   ├── _task.md _verify.md _summary.md _results.md _custom.md _orchestrator.md
│   ├── {backend,frontend,tests,dependencies}/<task>.md
│   └── orchestration/orchestration.md                    stray, unreferenced
├── schemas/report-format.md · stubs/phpstan.neon.stub · lang/{en,de}
src/
├── Actions/Install/ · Install/ · Services/InstallService.php    installer in three places
├── Agents/ (runner, jobs, outcomes, per-audit *Agent classes, Drivers/)
├── Audits/ (AuditDefinition, AuditTask, DependencyGuard)
├── Commands/ · Console/ · Contracts/ · Enums/ · Exceptions/
├── Data/{Composer,Phpstan,Routes,Run,Steps,Tests,Translations}  48 DTOs
├── Eval/ · Requirements/
├── Orchestration/ (AuditOrchestrator, AgentOrchestrator, Phases/, Tools/, WorkerLock, TaskStore)
├── Preflight/ (PreflightRunner, Steps/, Condensers/, DatabaseGuard, TestGate)
├── Prompts/ · Reports/ · Run/ · Snapshots/ · Support/
```

### 3.2 Proposed

```
resources/
├── audits/
│   ├── backend/
│   │   ├── audit.php                 tasks, checks, depends_on, snapshots, skills
│   │   └── tasks/<task>.md           one prompt per task
│   ├── frontend/ · tests/ · dependencies/   same layout
│   └── shared/
│       ├── task.md verify.md summary.md results.md custom.md
│       └── report-format.md          (was schemas/)
├── boost/
│   ├── guidelines/core.blade.php     ≤ 10 lines, always loaded
│   └── skills/
│       ├── laravel-audit/SKILL.md            how an agent runs the audit (orchestration)
│       └── laravel-audit-evidence/SKILL.md   how a task agent reports (inlined into task prompts)
├── lang/{en,de} · stubs/
src/
├── Commands/        Audit, Install, Tool, Eval
├── Run/             the fixed flow: Phases/, RunPlan, RunContext, JobPlanner, RunFinalizer, run.md/json
├── Tool/            audit:tool actions, WorkerLock, TaskStore   (was Orchestration/Tools + state)
├── Preflight/       Runner, Steps/, Condensers/, Snapshots/, Data/ (the step and tool DTOs), Guard
├── Audits/          AuditDefinition, AuditTask, Backend/Frontend/Tests/Dependencies rules (was Agents/*Agent)
├── Prompts/         templates, placeholders, SkillLoader
├── Agents/          runner, processes, jobs, outcomes, Drivers/
├── Reports/         unchanged
├── Install/         planner, executor, actions, service, BoostHandoff
├── Eval/ · Console/ · Contracts/ · Enums/ · Support/
```

Rules behind it: one directory per phase of the run (Preflight → Prompts/Audits → Agents → Reports → Run), every audit's definition and prompts side by side, Boost resources exactly where Boost looks (F1), and no directory that exists only because of a class-name suffix (`Actions/`, `Services/`).

### 3.3 Mapping (old → new)

| Old | New |
|---|---|
| `resources/audits/<audit>.php` | `resources/audits/<audit>/audit.php` |
| `resources/prompts/agents/<audit>/<task>.md` | `resources/audits/<audit>/tasks/<task>.md` |
| `resources/prompts/agents/_task.md` etc. | `resources/audits/shared/task.md` etc. |
| `resources/prompts/agents/_orchestrator.md` | `resources/boost/skills/laravel-audit/SKILL.md` (rewritten as a skill) |
| `resources/prompts/agents/orchestration/orchestration.md` | deleted |
| `resources/schemas/report-format.md` | `resources/audits/shared/report-format.md` |
| `src/Agents/{Backend,Frontend,Tests,Dependencies}Agent.php`, `OrchestrationAgent`, `ResultsAgent` | `src/Audits/<Audit>.php`; `ResultsAgent` → `src/Run/Overview.php`; `OrchestrationAgent` deleted |
| `src/Orchestration/AuditOrchestrator.php`, `Phases/*`, `RunPlan`, `RunContext` | `src/Run/` |
| `src/Orchestration/AgentOrchestrator.php`, `Phases/RunOrchestrator.php`, `OrchestrationState.php` | deleted (see §6) |
| `src/Orchestration/Tools/*`, `WorkerLock`, `TaskStore`, `OrchestratedRun` | `src/Tool/` |
| `src/Snapshots/*` | `src/Preflight/Snapshots/` |
| `src/Data/{Steps,Composer,Phpstan,Routes,Tests,Translations}` | `src/Preflight/Data/` |
| `src/Data/Run/*` | `src/Run/Data/` |
| `src/Actions/Install/*`, `src/Services/InstallService.php` | `src/Install/` |
| `src/Contracts/OrchestratingDriver.php` | deleted |
| published overrides `resources/prompts/vendor/laravel-audit`, `resources/audits/vendor/laravel-audit` | `resources/audits/vendor/laravel-audit/<audit>/{audit.php,tasks/}` and `/shared/`; the old layout is reported by the requirements check like the pre-task layout is today |

Publishing tags stay `audit-prompts` and `audit-checks`, now pointing at the same tree. `tests/Core/ClassnameTest` loses the `Actions`/`Services` rows.

## 4. Refactor

Rules: external behaviour unchanged, every existing test passes, readability over compactness, one commit per step.

### 4.1 Remove

- The headless agent orchestrator (`AgentOrchestrator`, `RunOrchestrator`, `OrchestrationState`, `OpenCodeConfig::orchestrator`, `OrchestratingDriver`, `_orchestrator.md`, the `orchestrator` config key, `--orchestrate`). Its job moves to the skill (§5). Its bugs (lock races, empty run exits 0, lost `--preflight-only`) disappear with it. Decided, see §8. What stays from it: `audit:tool`, `WorkerLock`, `TaskStore` and the worker, which the skill drives; their lock and atomic-write fixes are in phase 1.
- `orchestration/orchestration.md`, `.DS_Store` (twice), the dependabot workflow with the `:vendor_slug` placeholder, `docs/CONCEPT.md` sections about Dusk/`audit:prune`/`--history` (archive the file as `docs/archive/CONCEPT-v0.1.md`).
- Dead code named in the review: `Process::timeout` in `AgentProcesses::start`, the `method_exists` branch in `AgentProcesses::stop`, `PhpstanStep::dumpedLevel`'s extra process when `phpstan.neon` states the level.

### 4.2 Merge duplication

- Preflight and RunAgents setup exist three times (`Phases/Preflight`, `Work::preflight`, `AgentOrchestrator`): one `Run/PreflightPhase` used by both the fixed flow and `audit:tool`.
- Three JSONL parsers (Codex, OpenCode) → one `Drivers/EventStream` fold with per-driver event maps.
- Base-path relativizing exists four times (`JunitCondenser`, `CoverageCondenser`, `PhpstanMessage`, `ProjectInspector::relative`) → `ProjectInspector::relative`.
- `TestsStep`/`BrowserStep` share guard, skip, junit and log handling → `Steps/TestSuiteStep` base.
- 12 step DTOs and 12 Composer DTOs each hand-write `toArray`/`fromArray`; the snapshots then re-read the JSON as arrays. Keep the DTOs (readability), remove the third representation: snapshots read the DTOs, not the files, and `SnapshotWriter` gets the `StepResults` it already has in memory.

### 4.3 Fix while touching (behaviour changes, each with a test, listed so they are not hidden in a refactor commit)

- Security: Codex `resumeCommand` keeps `--sandbox read-only`; `DeniedFiles` also matches `**` and is re-evaluated when the worker starts a batch.
- Report pipeline: `Location::parse` rejects only when the path part is missing, everything else is a lint error; `EvidenceChecker` uses `Regex::lines`; README Effort `XS–XL` → `S/M/L`.
- Preflight: drain background stdout while light steps run (Symfony `Process::start` with a callback that appends to a buffer, or `wait()` in the poll loop); the RouteFacts auth heuristic matches middleware class names exactly (`Authenticate`, `EnsureFrontendRequestsAreStateful`, `auth`, `auth:*`, `Sanctum`), not the substring `auth`.

### 4.4 Database guard and child environment

- `.env.testing` is used only when the effective `APP_ENV` (phpunit `<env>`, then shell, then `.env`) is `testing`. Read `DB_URL` and `DB_PORT` too.
- `ChildEnvironment::inherited` keeps variables that are exported in the shell even if `.env` names them, because Laravel's `.env` loader never overwrites an existing variable.

### 4.5 Prompt tokens

Measured on the baseline (`run.json`, F11): a one-turn task ≈ 9.1–9.8k input tokens; multi-turn tasks pay the full context again per turn (`frontend.a11y` 17 turns → 452k, `tests.tooling` 9 turns → 157k, `frontend.access.verify` 13 turns → 172k). The per-turn context on OpenCode is roughly:

| Part | Size | Can we change it? |
|---|---|---|
| Task prompt (`_task.md` 4.8 KB + task 0.6–1.6 KB + snapshot 2–5 KB) | 8.5–11.8 KB ≈ 3k tokens | yes, see below |
| `AGENTS.md` (Boost guidelines) | 11.6 KB ≈ 3k tokens | no (F9); Claude Code with `--restricted` already skips it |
| Boost MCP tool schemas (8 tools) and CLI system prompt | ≈ 3k tokens | partly: fewer `boost_tools` per task |

Changes, with expected effect on the one-turn baseline:

1. Render each check once. `audit.php` already holds the check slugs and one-line descriptions; the task prompts repeat them as a "Checklist" with longer prose. Move the long prose into `audit.php` (`checks.<slug>.prompt`) and render it once under "Your checks". Saves 0.5–1.5 KB per task (5–15%).
2. Drop the four identical "Inputs" lines in `dependencies/{security,freshness,hygiene}.md` and the dangling bullets; one shared paragraph in `task.md`. ~0.3 KB.
3. Give each task only the Boost tools its snapshots do not already cover (`audit.php` `boost_tools`, default: `search-docs` and `application-info` only). Each removed tool schema saves ~100–300 tokens **per turn**.
4. Inline skills add tokens: only in tasks the selection assigned one to, at most two per task, each capped at 2 000 chars (~500 tokens), plus the one selection call per run (~4k tokens on a 50-skill project).
5. Verify prompt: list the finding, its quoted evidence and the exact question ("does the input reach `whereRaw` on line 41 without binding?"), and tell the model to answer after at most three file reads. Cap verify at `max_turns` 6. This is the biggest lever: verify passes were 13 of the 20 most expensive calls in the baseline.
6. Detail-by-ID is already the design (`preflight/details/<type>/<ID>.md`, F: `SnapshotWriter`). Evaluation for the brief's step 4: keep it; add nothing. The only improvement is to name the exact detail path per row in the snapshot table (already done) and to keep raw JSON out of reach (already a test: `never points task prompts at the raw preflight JSON files`).

Estimated total for a full run on the baseline project (28 tasks + verify): prompt trimming −10–15% of the one-turn cost; verify turn cap −40–60% of total input tokens, because the four runaway calls carried 65% of the run's input tokens. **Assumed** until measured with `audit:eval` on the fixture and one homemanager run.

## 5. Boost guideline, skill and install command

### 5.1 Guideline (always loaded, ≤ 10 lines)

`resources/boost/guidelines/core.blade.php`:

```
## Laravel Audit
This project has `astro2006/laravel-audit`. To audit the code base, activate the
`laravel-audit` skill and follow it; do not run the tools yourself. Reports are
written to `storage/audit/<run>/`. Never edit files during an audit.
```

Boost inlines it into `CLAUDE.md`/`AGENTS.md` under `=== astro2006/laravel-audit/core rules ===` (F3). Blade lets it stay silent when the package is not installed as a dev dependency (`@if`), **assumed** Boost renders package guidelines with Blade like its own (`GuidelineComposer` handles `.blade.php`; the render path for third-party files needs one test in a fixture project).

### 5.2 Skill `laravel-audit` (orchestration, loaded on demand)

Frontmatter `name: laravel-audit`, `description: Run the laravel-audit code audit: preflight tools, the four audits split into tasks, fetch preflight details, read the reports.` Body, in this order:

1. **Two ways to run.** `php artisan audit` runs everything deterministically (recommended in CI). The rest of the skill is the agent-driven way through `php artisan audit:tool`.
2. **Start.** `php artisan audit:tool open [--audits=…] [--force]` creates the run and prints the run id (new action: today the run is created by `AuditCommand --orchestrate`; the skill needs an entry point). `AUDIT_RUN` is not needed; every later call passes `--run=<id>`.
3. **Catalog.** `catalog` lists steps and tasks with `reads_steps`, `depends_on`, `skip_reason`, and the skill inventory (`name`, `description`) so the agent can assign skills to tasks (§2.3).
4. **Preflight.** `preflight <steps…>` in one call, then `wait`. The gate result comes back in `wait` as `gate: aborted|continue`; when `aborted`, the skill tells the agent to ask the user and to rerun `preflight --force` only on a yes. This replaces today's silent non-interactive abort.
5. **Tasks.** `start <task>[:skill,skill] …` per batch, `wait`, `status`; dependency rule (`depends_on` first); `result <task>` for findings that decide the next batch. The skill tells the agent to pick at most two skills per task from the catalog, by description, and only when a skill describes the task's domain. Concurrency is the harness's, not the agent's (§6).
6. **Finish.** `finish` writes the reports and `run.md` (new action; today `FinalizeRun` runs only inside the command). The skill says what to read afterwards (`run.md` overview, per-audit files) and how to interpret severity/confidence and "Rejected findings".
7. **Rules.** Only `php artisan audit:tool …`, never chained, never other commands; never edit files; the tasks run in sandboxed sessions the worker starts, the orchestrating session only coordinates.

Security note for the concept: the orchestrating session is the user's own interactive session with the user's own permissions; the audited code is only read by the sandboxed task sessions. Claude Code checks each subcommand of a compound command against permission rules (documented), OpenCode matches "parsed commands" (documented, chaining unspecified). The skill's rule is a convention for the agent, the sandbox for the tasks stays the enforcement.

### 5.3 Skill `laravel-audit-evidence` (inlined into task prompts)

The part of `_task.md` that is about *how to report* (evidence, locations, confidence, "project conventions win", grouping) moves here, so it exists once, is also usable by a human-driven session, and is what the harness inlines. `task.md` keeps the template and the field values because the parser depends on them.

### 5.4 Install command

`php artisan audit:install` exists and already runs `boost:install` when Boost is new. Add a final **Boost hand-off** step:

1. If `laravel/boost` is not installed: one line "Boost not installed, skills and guideline not registered", exit 0.
2. If installed but `boost.json` invalid: hint to run `php artisan boost:install`, exit 0 (Boost's own `boost:update` fails in this case, F4).
3. If the package is already in `boost.json` `packages`: run `php artisan boost:update --no-discover` after confirmation, so skills are refreshed.
4. Otherwise ask "Register laravel-audit's guideline and skill with Boost now? (runs `php artisan boost:update --discover`)". On yes, call it with a TTY (same mechanism as `boost:install` today) so Boost's multiselect can show the package.
5. Non-interactive (CI): Boost skips discovery anyway (F4), so `audit:install --yes` **writes the package name into `boost.json` `packages` itself** (decided 2026-09-23) and then runs `php artisan boost:update --no-discover`, which installs the skills and regenerates the guideline files without a prompt. `boost.json` is read and written as plain JSON with the existing keys kept in order; without `--yes` the non-interactive run only prints what it would do. Interactive runs still go through Boost's own prompt (step 4) so the user sees the same dialog Boost shows for every package.

README gets a section "Boost skill and guideline" and the install section names the hand-off. The manual is one command on a project that already has Boost: `php artisan boost:update` (Boost then prompts for the new package by itself).

## 6. Parallel execution

### 6.1 Verified vs assumed

| Question | Claude Code | OpenCode |
|---|---|---|
| Can the orchestrating session run several sub-tasks at once? | Agent tool exists; parallel launch not documented for `-p`; subagents inherit the parent's tool restrictions (documented) | `task` tool exists; parallelism not documented; inheritance of tools, permissions and skills not documented |
| Do sub-tasks see project skills? | not documented; under `--restricted` the parent itself does not (F6) | not documented |
| Can the harness run tasks concurrently? | yes, `AgentRunner` with `concurrency` (tested) | yes, same |

### 6.2 Decision

Concurrency stays **in the harness**, not in the agent. The orchestrating session calls `start a b c` once; the worker runs the batch with `AgentRunner` at `max_concurrency`. Reasons: it works identically for both CLIs and for local models; sub-agent fan-out is undocumented for both CLIs and would spend the orchestrator's context on task output; the sandbox per task is already there.

Config: rename `agent.mode`/`agent.concurrency` to one key `agent.max_concurrency` (default **1** = sequential, the safe default for subscription CLIs and Ollama). `--mode=parallel` becomes `--concurrency=N`. Legacy keys are warned like the others. The skill says: "The harness runs the batch at `max_concurrency`; with a local model keep it at 1, otherwise raise it in `config/audit.php`."

The one thing to verify by experiment before shipping, not by reading docs: an OpenCode orchestrating session issuing `php artisan audit:tool status; cat .env` against a `bash` permission of `php artisan audit:tool *` — does the parsed-command matcher deny the second part? Same for Claude Code with `Bash(php artisan audit:tool:*)` (documented to deny, verify once).

## 7. Work order

| Phase | Content | Done when |
|---|---|---|
| 0 | Tests from §1.4 (11 tests), no production change except what they force | green, failures documented as expected |
| 1 | Fixes from §4.3/§4.4 (security, guard, evidence, location) | tests from phase 0 pass |
| 2 | Folder structure §3, publishing paths, legacy-layout warning | `composer test`, `analyse`, `format` green; `vendor:publish` produces the new tree |
| 3 | Removal §4.1 and merges §4.2 | line count of `src/` down, no behaviour change (phase 0 tests) |
| 4 | Skill inventory, selection call and inlining §2.3, `agent.skills` config, token budget test | fixture run selects skills from a fixture inventory; invalid selection answer falls back to none with a warning |
| 5 | Boost resources §5.1–5.3, `audit:tool open|finish`, install hand-off §5.4, README | `boost:update` in the fixture project installs the skill into `.claude/skills` and `.agents/skills` |
| 6 | Concurrency key §6.2, prompt trimming §4.5, verify cap | one homemanager run compared with `2026-09-22_09-34-36` in tokens, turns, duration, findings |

## 8. Decisions taken (2026-09-23)

- Skills are **not** listed per task in the package. The harness discovers the installed skills and a model (one call per run, or the orchestrating agent) assigns them to tasks; task agents get the text inlined (§2.3).
- `audit:install --yes` may write `boost.json` itself for CI (§5.4).
- Local models: the design target is `qwen3-coder:30b` and the currently used `qwen3.x-27b` class; the selection call and the verify prompt must work with a strict, tiny JSON or table answer on these models. Anything larger only makes it cheaper.
- The headless agent orchestrator (`orchestrator => agent`, `--orchestrate`, `_orchestrator.md`, `RunOrchestrator`, `AgentOrchestrator`, `OrchestratingDriver`) is **dropped**. The skill-driven flow from the user's own session replaces it (§5.2); `audit:tool` stays as its backend.
- The skill selection is **cached across runs**: `skills.json` carries a hash of the inventory (names, descriptions, file sizes) and of the task list; a run reuses the latest run's selection when both hashes match, otherwise it selects again. `--reselect-skills` forces a fresh call.

## 9. Deferred until measured

Not decided on purpose; each needs numbers from phase 6 first:

1. **Verify pass**: same agent, or a second, cheaper `verify_model`? Measure first how far the turn cap alone brings the verify cost down.
2. **CI coverage**: pcov in `run-tests.yml`? Decide once phase 0 shows whether the listed gaps were the real ones.

## 10. Assumptions stated

- The code is this repository (`/Users/tobias/Projects/laravel-audit`), the reference app is `homemanager`.
- No new Composer dependency is needed for any step above.
- `--restricted` stays for Claude Code tasks, and the OpenCode task agent keeps `skill` denied; skills reach the model as prompt text.
- The selection call uses the configured task driver (same CLI, same model); it costs ≈ 4k input tokens on a project with 50 skills.
- Boost's third-party guideline rendering supports `.blade.php` for packages the same way as for its own files (code path reads both extensions; not run in a fixture yet).

## 11. Implementation status (2026-09-23, branch `feature/mvp2`)

All phases are implemented; 350 tests, PHPStan level 6 and Pint pass. Deviations from the plan above:

- **Order.** The headless orchestrator was removed (phase 3) before the folder move (phase 2), so fewer files had to move.
- **Folders.** `Actions/` and `Services/` stay, because the house rules in `tests/Core` name them. Artisan commands moved to `Console/Commands/` and their output helpers to `Console/Output/` (decided with the maintainer). `Contracts\Audit` is gone: the abstract `Audits\Audit` was its only implementation.
- **Skills (§2.3).** Implemented as decided: inventory, one selection call per run with a cache (`skills.json`, `--reselect-skills`), inlining. The inventory leaves out skills named `laravel-audit*`, so the orchestration skill is never given to a task. The separate `laravel-audit-evidence` skill (§5.3) was not created: the reporting rules stay in `shared/task.md`, where the parser depends on them, and a second copy would drift.
- **Concurrency (§6.2).** `agent.max_concurrency` (default 1) and `--concurrency` replace `agent.mode`/`agent.concurrency`/`--mode`.
- **Prompt tokens (§4.5).** Checks the task prompt already explains are listed by slug only, and the dependency inputs are shorter: 231 626 → 225 423 characters over all task prompts without snapshots (−2.7 %). Verify calls are capped at 6 tool calls. Per-task Boost tool lists were not done.
- **Not done from §4.2.** Snapshots still read the preflight JSON instead of the DTOs; `TestsStep` and `BrowserStep` share no base class; Codex and OpenCode keep their own event parsers. Each would add an abstraction for little gain.
- **Codex removed** (decided 2026-09-23). It works through a shell and cannot keep agents away from `.env`; the read-protection check that guarded it went with it.
- **Not verified against the real CLIs.** The skill selection call and OpenCode's second agent `laravel-audit-verify`. The next real run on homemanager should confirm them, together with the token numbers in §4.5.
