# Audit: Backend — Conventions, Security, Dead Code

`audit_key: backend` · `id_prefix: BE`

## Mission

Check whether the backend follows the project's own conventions and Laravel best practices, find obvious and not-so-obvious weaknesses in application logic, detect performance traps and identify dead code that can be removed safely.

## Inputs

- `{{preflight_dir}}/summary.json`
- `{{preflight_dir}}/pint-grouped.json` — style violations grouped by rule (do not list each file)
- `{{preflight_dir}}/phpstan/phpstan-grouped.json` — supporting evidence only
- `{{preflight_dir}}/routes.json` — to see which controllers and actions are reachable
- Project guidelines: `.ai/guidelines`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`

## Scope

**In:** `app/` (excluding rendering concerns), `config/`, `database/` (migrations, seeders, factories as code), `bootstrap/app.php`, service providers, jobs, events, listeners, commands, scheduler, mail and notifications, policies and gates (logic), API resources, domain services/actions. Controllers and Form Requests are in scope **for your checks** — mass assignment, inline validation, SQL injection and so on belong to you even when the input enters through a route.

**Out (other agents own these checks):** route-level access control, IDOR via route binding, throttling, views, JS, CSS (frontend); tests, failing tests and the PHPStan report — bugs PHPStan reports are `phpstan-bug` findings of the tests agent (tests); packages and versions (dependencies). Use PHPStan errors only as supporting evidence for your own findings (e.g. an unused private method as dead-code evidence). Note other observations under `### Out of scope observations`.

## Step 0 — Establish the conventions baseline

Before judging, determine the project's own conventions by sampling 5–10 representative files and reading the guidelines: architecture (actions, services, repositories, modules/domains), `declare(strict_types=1)`, `final` classes, DTOs, enums, Form Requests, API Resources, naming. Report deviations from the project's **dominant pattern**, and deviations from Laravel conventions only where they are not a deliberate, consistent choice. Summarize the baseline in the report (max ~10 lines).

## Checklist

### 1. Laravel conventions & best practices
- `env()` called outside `config/` (returns null once config is cached). High-value check. (`env-outside-config`)
- Validation inline in controllers where the project uses Form Requests. (`inline-validation`)
- Business logic in controllers, models or views; fat controllers or god classes. (`fat-controller`)
- Eloquent: missing relationship return types, missing `casts` (dates, enums, JSON, `encrypted`), mixed accessor styles, duplicated query logic that should be a scope, `Model::shouldBeStrict()` / `preventLazyLoading()` not enabled outside production. (`eloquent-hygiene`)
- Magic strings where enums exist or would fit; hardcoded values that belong in config. (`magic-values`)
- Transactions around multi-step writes; jobs/events dispatched inside transactions without `afterCommit`. (`transaction-safety`)
- Queues: idempotent jobs, `$tries` / `$backoff` / `$timeout`, `ShouldBeUnique` where duplicates hurt, serializing large models, `failed()` handling. (`queue-robustness`)
- Scheduler: `withoutOverlapping()` / `onOneServer()` for long or critical tasks. (`scheduler-overlap`)
- Error handling: swallowed exceptions (catch without rethrow or log), catching `\Throwable` broadly (`swallowed-exception`); exception messages leaked to users or API clients (`exception-leak`).
- Logging sensitive data (passwords, tokens, full request payloads, personal data). (`sensitive-logging`)
- Naming and structure: PSR-4, singular models, conventional table names, controller method names. (`naming-structure`) Pint results: one grouped `code-style` finding per relevant rule.

### 2. Security (application logic)
- **SQL injection:** `DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `DB::statement`, `DB::select` with interpolated variables — including sort, order and filter parameters from list views. (`sql-injection`)
- **Command injection:** `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, backticks, `Process::run()` with a string command containing input. (`command-injection`)
- **Unsafe deserialization:** `unserialize()` on untrusted data (without `allowed_classes`). (`unsafe-deserialization`)
- **SSRF:** HTTP client calls with user-controlled URLs or hosts. (`ssrf`)
- **Path traversal:** `Storage`, `File`, `fopen`, `include` with user-influenced paths. (`path-traversal`)
- **Authorization gaps** in actions/services invoked from several entry points (web, API, jobs, commands) where only one entry point checks permissions. (`authorization-gap`)
- **Mass assignment:** `$request->all()` / `input()` passed into `create()`, `update()`, `fill()` — in controllers and services; `$guarded = []` on models that receive request data. (`mass-assignment`)
- **Crypto & tokens:** `md5` / `sha1` for security purposes, homemade encryption, `rand()` / `mt_rand()` / `uniqid()` for tokens (use `Str::random()` / `random_bytes()`), secret comparison with `==` instead of `hash_equals()`. (`weak-crypto`)
- **Secrets:** hardcoded keys or tokens in code or config defaults, real-looking secrets in `.env.example`, `APP_DEBUG` defaulting to true. (`hardcoded-secret`)
- **Data exposure:** sensitive attributes missing from `$hidden` (password, remember_token, 2FA secrets), API Resources or `toArray()` exposing internals. (`data-exposure`)
- **Multi-tenancy:** global scopes that can be bypassed, queries missing the tenant constraint, soft-deleted data leaking. (`tenant-isolation`)
- **Auth config:** password rehashing, session invalidation on password change, password rules. (`auth-config`)

### 3. Logic
- Bugs you find by reading the code: wrong conditions, off-by-one, broken state transitions, rounding with money, timezone handling. (`logic-bug`)

### 4. Performance
- N+1 patterns and queries inside loops in PHP classes. (`n-plus-one`) Lazy loading triggered from views belongs to the frontend agent.
- `->get()` followed by collection filtering that belongs in SQL, `->get()->count()` instead of `->count()`, missing `chunk()` / `lazy()` for large datasets. (`inefficient-query`)
- Missing indexes on foreign keys and frequently filtered or sorted columns; unbounded text columns used in `where`. (`missing-index`)
- Repeated expensive queries without caching; caches without invalidation. (`missing-cache`)

### 5. Dead code
- Controllers or methods not reachable via any route, jobs never dispatched, events never fired, listeners not registered or discovered, commands never scheduled or called, mailables and notifications never sent, policies without a model mapping, services never resolved, enum cases never used, unused config keys, commented-out code blocks, stale feature flags. (`dead-code`)
- Old migrations are history, not dead code. Unused views and components belong to the frontend agent.
- **Verification required before claiming dead code:** search for the class name, `::class`, FQCN strings, container bindings, config arrays, event discovery, route definitions, Blade/Livewire references, queue or morph-map string names. Set confidence accordingly.

### 6. Custom checks
{{custom_checks}}

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Conventions baseline** — the project's dominant patterns from Step 0.
- **Convention scorecard** — Convention · Status (✅ / ⚠️ / ❌) · Evidence.
- **Dead code candidates** — Symbol · Type · Evidence of non-use · Confidence · Safe to delete (yes / after check / no).
