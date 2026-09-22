# Laravel Audit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/astro2006/laravel-audit.svg?style=flat-square)](https://packagist.org/packages/astro2006/laravel-audit)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/astro2006/laravel-audit/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/astro2006/laravel-audit/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/astro2006/laravel-audit/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/astro2006/laravel-audit/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/astro2006/laravel-audit.svg?style=flat-square)](https://packagist.org/packages/astro2006/laravel-audit)

An AI code audit for Laravel apps, started with one Artisan command.

```bash
php artisan audit
```

First, a **deterministic preflight** runs the tools you already trust: your test suite, your Pest browser tests, Larastan, Pint, `route:list`, `composer audit` and more. Their results are condensed into small JSON facts. After that, **read-only agents** audit the app in four areas: **tests, frontend, backend and dependencies**. Each area is split into small tasks of a few related checks, and every task is one headless run of an agent CLI: **Claude Code, OpenCode or Codex**, with whatever model you configured there, including self-hosted ones. The harness validates every task report against a fixed format, sends it back once for repair if needed, rejects it when it misses facts the preflight has proven (a known security advisory, a failing test), and assembles one Markdown report per area.

```
 laravel-audit 1.0.0 · Agent: Claude Code

 Requirements
  ✔ Agent: Claude Code · model from the CLI config
  ✔ Boost MCP (8 tools allowed)
  ✔ Larastan (phpstan.neon)
  ✔ Test database: sqlite :memory: (separate from the app database)
  ✔ Browser tests: tests/Browser (Pest + Playwright)

 Preflight
  ✔ App ............................................. Laravel 13.2.0 · PHP 8.4.1
  ✔ Stack ................................. livewire, tailwind, vite, pest, sanctum
  ✔ Lines of code .................................................. 18 342 LOC (app/)
  ✔ Pint ................................................... 12 files, 4 rules
  ✔ Routes ........................................................ 84 (6 flagged)
  ✔ Translations ................................................ de, en · 3 missing keys
  ✔ Composer .................................. 1 advisories, 2 abandoned, 5 major updates
  ✔ Tests .................................................. 412 passed · coverage 71.4 %
  ✔ Browser (Playwright) ...................................................... 18 passed
  ✔ PHPStan (level 6) ...................................... 37 errors + 112 baselined

 Agents
  · dependencies.security · 2 findings · 1m04s
  · backend.conventions · 1 findings · 1m31s
  · backend.injection · 0 findings · 2m12s
  …
  · backend.summary · 0 findings · 0m21s

  ✔ tests .............. 11 findings · 96 turns · 612k in / 19k out · 9m 12s
  ✔ frontend ........... 14 findings · 120 turns · 730k in / 22k out · 10m 40s
  ⚠ backend ............ 17 findings · 140 turns · 810k in / 24k out · 11m 05s · 1 of 11 tasks failed: logic
  ✔ dependencies ........ 6 findings · 31 turns · 140k in / 6k out · 3m 10s

 Findings: Critical 1 · High 7 · Medium 19 · Low 16 · Info 5
 → storage/audit/2026-09-11_14-02-11/run.md
```

> [!IMPORTANT]
> This is an MVP. The reports are a well-structured starting point for a human review, not a certificate. Agents can be wrong, and a run uses real tokens (see [Costs](#costs)).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Agents](#agents)
- [The four audits](#the-four-audits)
- [The preflight](#the-preflight)
- [Output](#output)
- [Security model](#security-model)
- [Configuration](#configuration)
- [Costs](#costs)
- [Evaluating prompt changes](#evaluating-prompt-changes)
- [Testing](#testing)

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- One agent CLI, installed and logged in: [Claude Code](https://docs.claude.com/claude-code) (`claude`), [OpenCode](https://opencode.ai) (`opencode`) or [Codex](https://developers.openai.com/codex) (`codex`). See [Agents](#agents)
- Composer

Optional, but recommended. `php artisan audit:install` sets these up for you:

| Package | What it adds |
|---|---|
| `laravel/boost` | Read-only MCP context for the agents (routes, schema, config, docs) |
| `brianium/paratest` | Parallel tests in the preflight |
| `pestphp/pest` 4+ and `pestphp/pest-plugin-browser` | Browser tests with Playwright |
| `playwright` (npm) | The browser engine for Pest browser tests |
| pcov or Xdebug | Coverage for the tests audit |

`larastan/larastan` and `laravel/pint` are dependencies of this package, so they are always available.

## Installation

Install the package as a dev dependency:

```bash
composer require --dev astro2006/laravel-audit
```

Then run the installer:

```bash
php artisan audit:install
```

The installer checks what is missing and shows you the plan before it changes anything:

```
 laravel-audit · Setup

 Already installed
  ✔ larastan/larastan
  ✔ laravel/pint
  ✔ pestphp/pest

 I need these packages:

  laravel/boost                Context for the agents (MCP)  composer --dev
  pestphp/pest-plugin-browser  Browser tests with Playwright  composer --dev
  playwright                   Browser engine (~ several 100 MB)  npm --save-dev
  ℹ No browser tests yet — the browser step is skipped until there are some.

 I would run:
  composer require --dev laravel/boost pestphp/pest-plugin-browser --with-all-dependencies
  npm install --save-dev playwright@latest
  php artisan boost:install    (interactive, only when Boost is new)
  npx playwright install
  create phpstan.neon (level 5)

 ┌ May I install these packages? ─────────────────────────────┐
 │ › ● Yes, all                                                │
 │   ○ Select                                                  │
 │   ○ No                                                      │
 └─────────────────────────────────────────────────────────────┘
```

- All Composer packages are installed with **one** `composer require` call, and all npm packages with one npm call. Before that, a dry run shows whether Composer would also change packages you did not ask for, and asks again if so.
- Upgrades are never implied. Moving from PHPUnit to Pest, or from Pest 3 to Pest 4+, is asked separately. Pest runs your existing PHPUnit tests unchanged. If you decline, only the browser step is left out.
- An existing `phpstan.neon` is never overwritten. If there is none, a Larastan config at `stub_level` (default 5) is created for `app/`.
- Reports are written below `storage/audit`, which gets its own `.gitignore` (`*` / `!.gitignore`) because reports describe concrete weaknesses.

Options:

```bash
php artisan audit:install --dry-run   # only show the plan
php artisan audit:install --yes       # install the plan without asking (upgrades are still skipped)
```

You can publish the config file if you want to change defaults:

```bash
php artisan vendor:publish --tag="audit-config"
```

## Usage

Run all four audits:

```bash
php artisan audit
```

Run only some of them:

```bash
php artisan audit --backend --dependencies
```

Leave out audits or single preflight steps for this run with `--without`:

```bash
php artisan audit --without=browser               # e.g. while Playwright does not work on this machine
php artisan audit --without=frontend --without=coverage
```

Allowed values: `tests`, `frontend`, `backend`, `dependencies`, `browser`, `phpstan`, `pint`, `coverage`.

Other options:

| Option | Effect |
|---|---|
| `--force` | Start the agents even if tests fail, without asking |
| `--preflight-only` | Run the tools only, no agents, no cost. Useful to check the setup |
| `--reuse-preflight[=RUN_ID]` | Skip the tools and reuse the preflight of the latest (or the given) run, e.g. after changing a prompt. You are warned if the code changed since |
| `--agent=NAME` | Agent CLI for this run: `claude_code`, `opencode`, `codex` or `auto` |
| `--model=MODEL` | Model for this run, in the format the agent CLI expects (e.g. `opus`, `ollama/qwen3-coder:30b`) |
| `--mode=MODE` | `single` (one task at a time, the default) or `parallel` (up to `agent.concurrency` tasks at once) |

### The test gate

The agents audit code, not a broken environment. If tests fail, you see the first failures and are asked whether to continue. If almost every test fails for the same reason (a missing driver, a missing table), the harness says so explicitly. Configure the behaviour with `preflight.tests.gate` (`ask`, `abort`, `continue`). Without interaction, `ask` aborts unless you pass `--force`. With `--preflight-only` the gate never stops the run, because no agents follow.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Run completed. Findings do not change the exit code |
| `2` | Aborted at the test gate |
| `3` | Harness error: a requirement is missing, or an audit is failed or partial because a task failed or its report stayed invalid after the repair pass |

## Agents

The package never talks to a model itself. It starts an agent CLI headless, once per task, and reads its JSON output. Models and providers are configured in that CLI, so anything the CLI supports works, including self-hosted models (for example Ollama through OpenCode or Codex).

| Driver | CLI | Tools the agent gets | Secret files (`deny_read`) |
|---|---|---|---|
| `claude_code` | `claude -p --restricted` | Read, Grep, Glob, allowed Boost tools | blocked for every tool |
| `opencode` | `opencode run --pure` with a generated config | read, glob, list, allowed Boost tools. No grep: OpenCode's grep returns denied files when given an `include` pattern | blocked |
| `codex` | `codex exec --sandbox read-only` | a read-only shell, allowed Boost tools | **not blocked**: Codex can read `.env`. The run only starts with `agent.drivers.codex.allow_unrestricted_read = true` |

`agent.driver` is `auto` by default: the first agent from Boost's `boost.json` that is installed, then Claude Code, OpenCode, Codex. Driver names are Boost's agent names. Pick one per run with `--agent`, and a model with `--model`:

```bash
php artisan audit --agent=opencode --model=ollama/qwen3-coder:30b
```

### Tasks

Small models get lost in long sessions with dozens of checks. So every audit is split into tasks of 2–7 related checks that read the same code, for example `backend.injection` (SQL, command injection, deserialization, SSRF, path traversal) or `tests.phpstan`. Each task is one short agent session with its own prompt. In `single` mode (the default) they run one after the other; in `parallel` mode up to `agent.concurrency` at once. Single is the default because a self-hosted model server usually answers one request at a time, and parallel sessions against it queue up, time out or fail; subscription CLIs run into their rate limits. Switch to `parallel` (`AUDIT_MODE=parallel` or `--mode=parallel`) for a hosted API or a model server that serves concurrent requests. While the agents run, the console lists every task up front, shows the running ones with their time, what still waits, and each task as it finishes. Only tasks that need another task's result wait for it and get that result in their prompt: the backend conventions baseline and the frontend map. After each task, a verify call with the same agent checks its findings of medium severity and above (`keep`, `downgrade` or `drop`); findings backed by a preflight fact are not verified. The harness writes each audit's summary from the findings (`summary => 'agent'` brings back a summary task). Tasks with nothing to audit, such as the advisories task without advisories or the PHPStan task without errors, are skipped without an agent call.

The harness assembles the audit report: findings in task order with new IDs, each audit-specific section from the task that owns it, merged positive observations and limitations. When a task fails, the audit is **partial**: the report is still written, lists the failed task and the checks it did not cover, and the run exits with `3`.

Once every selected audit is done, one more agent call writes the run's overview: it reads each audit's own summary and finding counts and calls out patterns a single audit could not see on its own (the same root cause showing up in more than one audit, for example). It always runs, at no extra config, and its answer becomes the `## Overview` at the top of `run.md`.

## The four audits

Every audit owns a fixed list of checks, and every check belongs to exactly one audit and one task, so the same problem is not reported several times.

| Audit | ID prefix | Covers | Gets from the preflight |
|---|---|---|---|
| **Tests** | `TST` | Failing and skipped tests, untested critical paths, weak assertions, missing fakes, flakiness, factories, missing authorization/validation/security tests, browser test coverage, PHPStan bugs and typing debt, the PHPStan baseline, CI quality gates | JUnit and coverage summaries, route → test map, browser test results and screenshots, grouped PHPStan errors |
| **Frontend** | `FE` | Route authorization, IDOR, state-changing GETs, throttling, CSRF exclusions, signed URLs, webhooks, XSS, Livewire and Inertia specifics, open redirects, uploads, client-side secrets, security headers, sessions and CORS, error states, UI consistency, design tokens, i18n, responsiveness, accessibility, asset performance, dead frontend code | Route facts (unauthenticated writes, missing throttling, debug routes, broken routes), translation diff, browser-visited URLs |
| **Backend** | `BE` | `env()` outside config, validation, fat controllers, Eloquent hygiene, transactions, queues, scheduler, exceptions and logging, conventions, Pint rules, SQL/command injection, deserialization, SSRF, path traversal, authorization gaps, mass assignment, crypto, secrets, data exposure, tenant isolation, logic bugs, N+1 and query performance, indexes, caching, dead code | Pint rule groups, routes, stack |
| **Dependencies** | `DEP` | Security advisories, abandoned, unused, misplaced, shadow and redundant packages, outdated majors, unsupported runtimes, loose constraints, lock file sync, Composer config, autoload bloat, licenses | `composer audit/outdated/licenses/validate/why`, a usage map of which package is used where |

Add project-specific rules per audit with `custom_checks`:

```php
'custom_checks' => [
    'backend' => [
        'Money is always stored as integer cents.',
    ],
],
```

## The preflight

Tools run once, before any agent starts. Agents never run tools themselves, and they do not read the tool output either. Each task gets a compact **snapshot** in its prompt: metrics and one row per ID (a failure group, a PHPStan identifier, an advisory …), without messages, stack traces or code. For example:

```
### Tests — 🔴 FAILED
Total 2450 · Passed 2442 · Failed 8 · Skipped 0 · 312.4 s

| ID | Tests | Exception | Files |
|---|---|---|---|
| T-01 | 5 | QueryException | tests/Feature/OrderTest.php |

Details per ID: `storage/audit/<run>/preflight/details/tests/<ID>.md`
```

The details of an ID (the message, the failing tests, examples, usage) are in a small file of their own, which the agent opens only for the IDs it judges. Every task sees the status of all preflight steps, plus only the snapshots its checks need. `snapshot_rows` (default 15) limits the rows per snapshot in the prompt; the rest are listed in `_all.md`.

| Step | Tool | Used by |
|---|---|---|
| App, Stack, Lines of code | `artisan about`, `composer.lock`, `package.json` | all |
| Pint | `pint --test --format=json` | backend |
| Routes | `artisan route:list --json` | frontend, backend, tests |
| Translations | `lang/` diff between locales | frontend |
| Composer | `composer audit`, `outdated`, `licenses`, `validate`, `why` | dependencies |
| Tests | `artisan test` with JUnit and Clover (parallel if paratest is installed) | tests |
| Browser | Pest browser tests (Playwright) | tests, frontend |
| PHPStan | Larastan with your `phpstan.neon` and baseline | tests |

Light steps run in parallel. Heavy steps run one after another: tests, then the gate, then browser tests, then PHPStan.

### Browser tests

Browser tests are Pest 4+ browser tests with Playwright. They are found through a `Browser` test suite in `phpunit.xml`, or through `tests/Browser`, and they are excluded from the regular test step. If the project has **no browser tests**, the step is skipped quietly and everything else runs as usual. If there are browser tests but the plugin or Playwright is missing, the step is skipped with a hint to run `audit:install`. If the built frontend assets are missing, they are built first (`preflight.browser.build_assets`).

### The database guard

Tests with `RefreshDatabase` wipe the database they run against. Before any test runs, the guard resolves the test connection the way Laravel does: `.env.testing` replaces `.env`, `phpunit.xml` `<env>` wins over it, and variables exported in your shell win over `<env>` without `force="true"`. The guard then compares the test connection with the app database. If they could be the same, tests and browser tests are **skipped**, not run. When in doubt, it says "not safe".

The test process itself starts without the variables `php artisan audit` loaded from your `.env`, like a fresh shell. So the guard's result is also what the tests really use.

## Output

Every run gets its own folder:

```
storage/audit/
├── .gitignore
└── 2026-09-11_14-02-11/
    ├── run.md               # overview: cross-audit summary, preflight, metrics, audits, tasks, findings, possible duplicates
    ├── run.json             # the same, machine-readable
    ├── tests.md
    ├── frontend.md
    ├── backend.md
    ├── dependencies.md
    ├── preflight/           # condensed tool results (summary.json, route-facts.json, tests/, phpstan/, composer/ …)
    │   ├── snapshots/       # what each prompt shows: metrics and IDs per data source
    │   └── details/         # one small file per ID, opened by the agent on demand
    └── logs/                # per task: rendered prompt, agent output as formatted JSON, reports that failed validation; tool logs
```

### Report format

Every report has frontmatter and the same structure. Every finding carries a fixed field table:

```markdown
### BE-003 — Unfiltered request data passed to OrderService::update()

| Field | Value |
|---|---|
| Severity | high |
| Confidence | confirmed |
| Category | security |
| Check | mass-assignment |
| Effort | S |
| Locations | `app/Http/Controllers/OrderController.php:57`, `app/Services/OrderService.php:7-12` |
| Occurrences | 2 |
| References | CWE-915 |

**Evidence**
…

**Impact**
…

**Recommendation**
…
```

- **Severity** `critical` · `high` · `medium` · `low` · `info`. **Confidence** `confirmed` · `likely` · `suspected`. **Effort** `XS` – `XL`.
- Locations are files with lines, `route:METHOD /uri`, `package:vendor/name`, or `missing:path` when the absence of a file is the finding.
- The harness drops findings it can disprove, without a repair pass: a file that does not exist, a `missing:` file that does exist, quoted code that is not in the file, or a dependency finding that composer contradicts (an advisory or abandoned package composer does not report, a package that is not locked). `run.md` lists them under "Rejected findings".
- Each finding gets a stable key (audit, check and targets, without line numbers). Keys let you compare runs. Findings that would share a key within one report are told apart by their line, then their title.
- Findings from different audits, or from different tasks of one audit, that point to the same place are listed in `run.md` as possible duplicates. They are not merged.
- There is no grade. `run.md` shows metrics (coverage, PHPStan errors, advisories …) and experimental risk points that you can weight in the config.

The full specification is in [`resources/schemas/report-format.md`](resources/schemas/report-format.md).

### Report language

Reports and console output use `report_language`, or `app.locale` if it is not set. English and German console output are included. The report prose follows the language; field names and enum values stay English so they remain machine-readable.

## Security model

The agents look at your code. They must not change it or see your secrets. Each driver maps these rules to its CLI (see [Agents](#agents)):

- **Read-only tools.** No agent gets write tools, web access or code execution.
  - Claude Code gets `Read`, `Grep` and `Glob` only, runs with `--restricted` (your settings files do not apply) and `--permission-prompts none`, so nothing can be approved by accident.
  - OpenCode gets a generated config: every tool off except read, glob and list, every other permission denied, never "ask", `--pure` (no plugins).
  - Codex runs in its read-only sandbox. It works through a shell, so it can run read-only commands.
- **No secrets.** `.env*` (except `.env.example`), `auth.json` and `storage/*.key` are denied by explicit rules (`agent.deny_read`, `agent.allow_read`). Claude Code and OpenCode enforce this; Codex cannot and only runs when you accept that. Every child process starts without the variables loaded from your `.env`.
- **Read-only Boost.** Boost's MCP server (`php artisan boost:mcp`) is the only MCP server, and only an allowlist of its tools is enabled (`agent.boost_tools`). Tinker, database queries, log readers and `get-config` (it returns secret values) are always denied.
- **Never in production.** `php artisan audit` refuses to run when `APP_ENV` is `production`.
- **Test database guard.** See [above](#the-database-guard).
- **Reports stay local.** The output folder is git-ignored by its own `.gitignore`.

Everything the agents see goes to the model provider of your agent CLI (Anthropic, OpenAI, or your own server for self-hosted models). Only audit code you are allowed to share with that provider.

## Configuration

Publish the config with `php artisan vendor:publish --tag="audit-config"`. The most important keys:

```php
return [
    'output_path' => env('AUDIT_OUTPUT_PATH', storage_path('audit')),
    'report_language' => env('AUDIT_LANGUAGE'),              // null = app.locale

    'agent' => [
        'driver' => env('AUDIT_AGENT', 'auto'),             // claude_code, opencode, codex or auto
        'model' => env('AUDIT_MODEL'),                      // overrides the driver model; null = CLI default
        'mode' => env('AUDIT_MODE', 'single'),              // single: one task at a time; parallel: up to concurrency
        'concurrency' => env('AUDIT_CONCURRENCY', 4),       // tasks at once in parallel mode
        'timeout' => 900,                                   // seconds per task
        'verify' => ['enabled' => true, 'min_severity' => 'medium'], // second call per task that checks its findings
        'drivers' => [
            'claude_code' => ['binary' => 'claude', 'model' => null, 'effort' => null, 'max_budget_usd' => null /* per task */],
            'opencode' => ['binary' => 'opencode', 'model' => null /* provider/model */],
            'codex' => ['binary' => 'codex', 'model' => null, 'config' => [/* -c overrides */], 'allow_unrestricted_read' => false],
        ],
        'boost_tools' => [/* read-only allowlist */],
        'deny_read' => ['.env*', 'auth.json', 'storage/*.key'],
        'allow_read' => ['.env.example'],
    ],

    'audits' => ['tests' => true, 'frontend' => true, 'backend' => true, 'dependencies' => true],

    'preflight' => [
        'allow_shared_database' => false,   // only if you know what you do
        'pint' => true,
        'tests' => ['command' => null, 'parallel' => true, 'coverage' => true, 'gate' => 'ask', 'timeout' => 1800],
        'browser' => ['enabled' => true, 'testsuite' => 'Browser', 'path' => 'tests/Browser', 'build_assets' => 'if-missing'],
        'phpstan' => ['enabled' => true, 'level' => null, 'stub_level' => 5, 'memory_limit' => '2G'],
        'composer' => ['outdated' => true, 'licenses' => true],
        'license_allowlist' => ['MIT', 'BSD-3-Clause', 'Apache-2.0', /* … */],
    ],

    'max_findings_per_task' => 5,           // agents group instead of listing more
    'max_files_per_task' => 25,             // files an agent should open at most
    'summary' => 'generated',               // generated (no agent call) or agent
    'exclude_paths' => ['vendor', 'node_modules', 'storage', 'public/build', 'bootstrap/cache'],
    'custom_checks' => ['tests' => [], 'frontend' => [], 'backend' => [], 'dependencies' => []],
    'risk_points' => [/* weights, experimental */],
];
```

### Customizing prompts

The prompts are plain Markdown. Publish them to change wording or focus:

```bash
php artisan vendor:publish --tag="audit-prompts"
```

They land in `resources/prompts/vendor/laravel-audit` and win over the package prompts: `agents/_task.md` holds the shared rules and output format, `agents/<audit>/<task>.md` the instructions of one task, `agents/_summary.md` the summary. Each audit is a single self-contained class in `Astro2006\LaravelAudit\Agents` (`BackendAgent`, `FrontendAgent`, `DependenciesAgent`, `TestsAgent`): it owns its checks, tasks and sections, and generates its own prompts. The report format is enforced by the harness, whatever the prompt says. Each report records a hash of the prompts it was produced with; the task hashes are in `run.md`. Prompts published before the task layout (`_base.md`, `backend.md` …) are ignored, and the requirements check tells you so.

To change the console texts, publish the translations with `php artisan vendor:publish --tag="audit-translations"`.

## Costs

A full run is about 30 short agent sessions (tasks). With a subscription CLI they count against your plan's usage limits; with an API key they are billed; with a self-hosted model they cost only compute. Every audit line and `run.md` show tokens, turns, duration and, where the CLI reports it, cost. Limit the spend per task with `AUDIT_MAX_BUDGET_USD` (Claude Code), and stay in `single` mode or lower `agent.concurrency` if you hit rate limits. Use `--preflight-only` to check the setup for free, and `--reuse-preflight` to rerun agents without rerunning the tools.

## Evaluating prompt changes

Prompt changes need numbers, not impressions. `audit:eval` runs the audits several times against a fixture app with known ("planted") problems and a few negative controls. It measures recall, severity accuracy, false alarms on the controls, stability of finding keys between runs, format repairs and cost:

```bash
php artisan audit:eval path/to/expected.json --runs=3 --audit=backend
```

```json
{
    "planted": [
        {"id": "P01", "audit": "backend", "check": "env-outside-config", "file": "app/Services/Pay.php"},
        {"id": "D01", "audit": "dependencies", "check": "vulnerable-dependency", "package": "guzzlehttp/psr7"}
    ],
    "negative_controls": [
        {"id": "N01", "audit": "backend", "check": "sql-injection", "file": "app/Repositories/ReportRepository.php"}
    ]
}
```

The command is hidden from `php artisan list` because it is meant for working on the package itself. Every run uses tokens. To compare agents or models, set `AUDIT_AGENT` and `AUDIT_MODEL` for the eval run.

## Testing

```bash
composer test       # Pest
composer analyse    # PHPStan
composer format     # Pint
```

The test suite never starts a real agent. Tools and the agent CLIs are faked with `Process::fake()`; fake answers are routed per task through the `AUDIT_TASK` variable the runner sets.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Pull requests are welcome. Please add tests and run `composer test`, `composer analyse` and `composer format` before you open one.

## Security Vulnerabilities

Please report security vulnerabilities by email to the maintainer instead of opening a public issue.

## Credits

- [Tobias Brogle](https://github.com/astro2006)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
