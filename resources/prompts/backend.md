# Audit: Backend — Conventions, Security, Dead Code

`audit_key: backend` · `id_prefix: BE`

## Mission

Check whether the backend follows the project's own conventions and Laravel best practices, find obvious and not-so-obvious weaknesses in application logic, detect performance traps and identify dead code that can be removed safely.

## Inputs

- `{{preflight_dir}}/summary.json`
- `{{preflight_dir}}/pint.json` — style violations (group by rule, do not list each file)
- `{{preflight_dir}}/phpstan/phpstan-grouped.json` — supporting evidence only (the tests agent owns the PHPStan report)
- `{{preflight_dir}}/routes.json` — to see which controllers and actions are reachable
- Project guidelines: `.ai/guidelines`, Boost guidelines, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`
- Boost MCP: database schema (indexes, foreign keys), application info

## Scope

**In:** `app/` (excluding rendering concerns), `config/`, `database/` (migrations, seeders, factories as code), `bootstrap/app.php`, service providers, jobs, events, listeners, commands, scheduler, mail and notifications, policies and gates (logic), API resources, domain services/actions.

**Out:** Views, JS, CSS, route-level access control (frontend agent), tests and the PHPStan report (tests agent), packages and versions (dependencies agent). Do not restate PHPStan errors as findings; use them only to support your own findings (e.g. an unused private method as dead-code evidence).

## Step 0 — Establish the conventions baseline

Before judging, determine the project's own conventions by sampling 5–10 representative files and reading the guidelines: architecture (actions, services, repositories, modules/domains), `declare(strict_types=1)`, `final` classes, DTOs, enums, Form Requests, API Resources, naming. Report deviations from the project's **dominant pattern**, and deviations from Laravel conventions only where they are not a deliberate, consistent choice. Summarize the baseline in the report (max ~10 lines).

## Checklist

### 1. Laravel conventions & best practices
- `env()` called outside `config/` (returns null once config is cached). High-value check.
- Validation inline in controllers where the project uses Form Requests; `authorize()` returning `true` with no policy check elsewhere.
- Business logic in controllers, models or views; fat controllers or god classes (many responsibilities, very long methods).
- Eloquent: missing relationship return types, missing `casts` (dates, enums, JSON, `encrypted`), `$guarded = []`, mixed accessor styles, duplicated query logic that should be a scope, `Model::shouldBeStrict()` / `preventLazyLoading()` not enabled outside production.
- Magic strings where enums exist or would fit; hardcoded values that belong in config; inconsistent DI vs facade usage (only if the project has a clear rule).
- Transactions around multi-step writes; jobs/events dispatched inside transactions without `afterCommit`.
- Queues: idempotent jobs, `$tries` / `$backoff` / `$timeout`, `ShouldBeUnique` where duplicates hurt, serializing large models, `failed()` handling.
- Scheduler: `withoutOverlapping()` / `onOneServer()` for long or critical tasks.
- Error handling: swallowed exceptions (catch without rethrow or log), catching `\Throwable` broadly, exception messages leaked to users or API clients.
- Logging sensitive data (passwords, tokens, full request payloads, personal data).
- Naming and structure: PSR-4, singular models, conventional table names, controller method names. Pint results: one grouped finding per relevant rule.

### 2. Security (application logic)
- **SQL injection:** `DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `DB::statement`, `DB::select` with interpolated variables.
- **Command injection:** `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, backticks, `Process::run()` with a string command containing input.
- **Unsafe deserialization:** `unserialize()` on untrusted data (without `allowed_classes`), untrusted serialized payloads in cache/queue.
- **SSRF:** HTTP client calls with user-controlled URLs or hosts.
- **Path traversal:** `Storage`, `File`, `fopen`, `include` with user-influenced paths.
- **Authorization gaps** in actions/services invoked from several entry points (web, API, jobs, commands) where only one entry point checks permissions.
- **Mass assignment** inside services (`fill($data)` with unfiltered arrays).
- **Crypto & tokens:** `md5` / `sha1` for security purposes, homemade encryption, `rand()` / `mt_rand()` / `uniqid()` for tokens (use `Str::random()` / `random_bytes()`), secret comparison with `==` instead of `hash_equals()`.
- **Secrets:** hardcoded keys or tokens in code or config defaults, real-looking secrets in `.env.example`, `APP_DEBUG` defaulting to true.
- **Data exposure:** sensitive attributes missing from `$hidden` (password, remember_token, 2FA secrets), API Resources or `toArray()` exposing internals.
- **Multi-tenancy:** global scopes that can be bypassed, queries missing the tenant constraint, soft-deleted data leaking.
- **Auth config:** password rehashing, login throttling, session invalidation on password change.

### 3. Performance
- N+1 patterns (relationship access in loops), queries inside loops, `->get()` followed by collection filtering that belongs in SQL, `->get()->count()` instead of `->count()`, missing `chunk()` / `lazy()` for large datasets.
- Migrations and schema: missing indexes on foreign keys and frequently filtered or sorted columns (the need depends on the DB driver; check with Boost's schema tool), unbounded text columns used in `where`.
- Repeated expensive queries without caching; caches without invalidation.

### 4. Dead code
- Controllers or methods not reachable via any route, jobs never dispatched, events never fired, listeners not registered or discovered, commands never scheduled or called, mailables and notifications never sent, policies without a model mapping, services never resolved, enum cases never used, unused config keys, commented-out code blocks, stale feature flags.
- Old migrations are history, not dead code. Do not report them.
- **Verification required before claiming dead code:** search for the class name, `::class`, FQCN strings, container bindings, config arrays, event discovery, route definitions, Blade/Livewire references, queue or morph-map string names. Set confidence accordingly.

### 5. Custom checks
{{custom_checks}}

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Conventions baseline** — the project's dominant patterns from Step 0.
- **Convention scorecard** — Convention · Status (✅ / ⚠️ / ❌) · Evidence.
- **Dead code candidates** — Symbol · Type · Evidence of non-use · Confidence · Safe to delete (yes / after check / no).
