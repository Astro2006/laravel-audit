# Laravel Audit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/astro2006/laravel-audit.svg?style=flat-square)](https://packagist.org/packages/astro2006/laravel-audit)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/astro2006/laravel-audit/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/astro2006/laravel-audit/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/astro2006/laravel-audit/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/astro2006/laravel-audit/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/astro2006/laravel-audit.svg?style=flat-square)](https://packagist.org/packages/astro2006/laravel-audit)

An AI code audit for Laravel apps, started with one Artisan command.

```bash
php artisan audit
```

First, a **deterministic preflight** runs the tools you already trust: your test suite, your Pest browser tests, Larastan, Pint, `route:list`, `composer audit` and more. Their results are condensed into small JSON facts. After that, four **read-only Claude Code agents** audit the app in parallel: **tests, frontend, backend and dependencies**. Each writes a Markdown report. The harness validates every report against a fixed format, sends a report back once for repair if needed, and fails the run when a report misses facts the preflight has proven, such as a known security advisory or a failing test.

```
 laravel-audit 1.0.0 · Agent: claude

 Requirements
  ✔ Claude Code CLI
  ✔ Boost MCP (9 tools allowed)
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
  ✔ tests .............. 11 findings · 38 turns · 412k in / 9k out · $1.84 · 6m 12s
  ✔ frontend ........... 14 findings · 45 turns · 530k in / 12k out · $2.31 · 7m 40s
  ✔ backend ............ 17 findings · 52 turns · 610k in / 14k out · $2.77 · 8m 05s
  ✔ dependencies ........ 6 findings · 14 turns · 120k in / 4k out · $0.52 · 2m 10s

 Findings: Critical 1 · High 7 · Medium 19 · Low 16 · Info 5
 → storage/audit/2026-09-11_14-02-11/run.md
```

> [!IMPORTANT]
> This is an MVP. The reports are a well-structured starting point for a human review, not a certificate. Agents can be wrong, and a run costs real money (see [Costs](#costs)).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
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
- The [Claude Code CLI](https://docs.claude.com/claude-code), installed and logged in (`claude` in your `PATH`)
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
  php artisan boost:install    (interactive)
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
- An existing `phpstan.neon` is never overwritten. If there is none, a Larastan config at `stub_level` (default 5) is created for `app/` and `tests/`.
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

### The test gate

The agents audit code, not a broken environment. If tests fail, you see the first failures and are asked whether to continue. If almost every test fails for the same reason (a missing driver, a missing table), the harness says so explicitly. Configure the behaviour with `preflight.tests.gate` (`ask`, `abort`, `continue`). Without interaction, `ask` aborts unless you pass `--force`. With `--preflight-only` the gate never stops the run, because no agents follow.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Run completed. Findings do not change the exit code |
| `2` | Aborted at the test gate |
| `3` | Harness error: a requirement is missing, an agent failed, or a report stayed invalid after the repair pass |

## The four audits

Every audit owns a fixed list of checks. A check belongs to exactly one audit, so the same problem is not reported four times.

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

Tools run once, before any agent starts. Agents never run tools themselves; they only read the condensed results.

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
    ├── run.md               # overview: preflight, metrics, findings per audit, possible duplicates
    ├── run.json             # the same, machine-readable
    ├── tests.md
    ├── frontend.md
    ├── backend.md
    ├── dependencies.md
    ├── preflight/           # condensed tool results (summary.json, route-facts.json, tests/, phpstan/, composer/ …)
    └── logs/                # rendered prompts, raw agent output, tool logs, reports that failed validation
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
- Locations are files with lines, `route:METHOD /uri` or `package:vendor/name`. Locations that do not exist in the project are flagged as possibly hallucinated.
- Each finding gets a stable key (audit, check and targets, without line numbers). Keys let you compare runs.
- Findings from different audits that point to the same place are listed in `run.md` as possible duplicates. They are not merged.
- There is no grade. `run.md` shows metrics (coverage, PHPStan errors, advisories …) and experimental risk points that you can weight in the config.

The full specification is in [`resources/schemas/report-format.md`](resources/schemas/report-format.md).

### Report language

Reports and console output use `report_language`, or `app.locale` if it is not set. English and German console output are included. The report prose follows the language; field names and enum values stay English so they remain machine-readable.

## Security model

The agents look at your code. They must not change it or see your secrets.

- **Read-only tools.** Agents get `Read`, `Grep` and `Glob` only. `Edit`, `Write`, `Bash`, web access and all other tools are denied, and Claude Code runs with `--permission-prompts none`, so nothing can be approved by accident.
- **Restricted mode.** Claude Code runs with `--restricted`: your user and project settings files (for example an allow rule for `Bash` in `.claude/settings.json`) do not apply, and file tools stay inside the project.
- **No secrets.** `.env*` (except `.env.example`), `auth.json` and `storage/*.key` are denied to `Read` by explicit rules (`agent.deny_read`, `agent.allow_read`). The agent process also starts without the variables loaded from your `.env`.
- **Read-only Boost.** Only an allowlist of Boost MCP tools is enabled (`agent.boost_tools`). Tinker, database queries and log readers are always denied. No other MCP servers are loaded (`--strict-mcp-config`).
- **Never in production.** `php artisan audit` refuses to run when `APP_ENV` is `production`.
- **Test database guard.** See [above](#the-database-guard).
- **Reports stay local.** The output folder is git-ignored by its own `.gitignore`.

Everything the agents see goes to Anthropic as part of a normal Claude Code session. Only audit code you are allowed to share with Claude.

## Configuration

Publish the config with `php artisan vendor:publish --tag="audit-config"`. The most important keys:

```php
return [
    'output_path' => env('AUDIT_OUTPUT_PATH', storage_path('audit')),
    'report_language' => env('AUDIT_LANGUAGE'),              // null = app.locale

    'agent' => [
        'binary' => env('AUDIT_CLAUDE_BINARY', 'claude'),
        'model' => env('AUDIT_MODEL'),                      // null = CLI default
        'effort' => env('AUDIT_EFFORT'),                    // low, medium, high, xhigh, max
        'max_budget_usd' => env('AUDIT_MAX_BUDGET_USD'),    // per agent, null = no limit
        'concurrency' => 4,                                 // lower to 2 on rate limits
        'timeout' => 1800,                                  // seconds per agent
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

    'max_findings' => 25,                   // agents group instead of listing more
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

They land in `resources/prompts/vendor/laravel-audit` and win over the package prompts. The report format is enforced by the harness, whatever the prompt says. Each report records a hash of the prompts it was produced with.

To change the console texts, publish the translations with `php artisan vendor:publish --tag="audit-translations"`.

## Costs

Each audit is one Claude Code session. Depending on app size and model, a full run of four audits typically costs a few dollars and takes 5–15 minutes. Every agent line and `run.md` show tokens, turns, cost and duration. Limit the spend per agent with `AUDIT_MAX_BUDGET_USD`. Use `--preflight-only` to check the setup for free, and `--reuse-preflight` to rerun agents without rerunning the tools.

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

The command is hidden from `php artisan list` because it is meant for working on the package itself. Every run is billed.

## Testing

```bash
composer test       # Pest
composer analyse    # PHPStan
composer format     # Pint
```

The test suite never starts a real agent. Tools and the Claude CLI are faked with `Process::fake()`.

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
