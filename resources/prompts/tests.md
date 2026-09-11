# Audit: Tests & Static Analysis

`audit_key: tests` · `id_prefix: TST`

## Mission

Judge whether the test suite actually protects this application: what is tested, how well, what is missing, and how healthy the codebase is under PHPStan at the project's configured level ({{phpstan_level}}). You assess test results and test code. You do not run anything.

## Inputs

Read the condensed files first; open raw files only for details.

- `{{preflight_dir}}/summary.json` — step status (tests, browser, phpstan), counts, coverage availability, skip reasons
- `{{preflight_dir}}/tests/tests-condensed.json` — totals, failures grouped by exception, skipped tests with reasons, 10 slowest tests
- `{{preflight_dir}}/tests/browser-condensed.json` — browser test results: failures, `environment_error`, screenshots per failed test, visited URIs per test file (only if browser tests ran)
- `{{preflight_dir}}/tests/browser-artifacts/` — screenshots of failed browser tests; you can view images
- `{{preflight_dir}}/tests/coverage-by-class.json` — line coverage per class in `app/`, uncovered methods, lowest first (may be missing; see `summary.json`)
- `{{preflight_dir}}/tests/route-test-map.json` — per route, the test files that reference it (heuristic; verify)
- `{{preflight_dir}}/phpstan/phpstan-grouped.json` — PHPStan errors grouped by identifier and file; baselined errors are marked `baselined`
- `{{preflight_dir}}/routes.json` — all routes with middleware
- Raw files: `tests/junit.xml`, `tests/coverage.xml`, `tests/browser-junit.xml`, `phpstan/phpstan.json`
- Test config: `phpunit.xml`, `tests/Pest.php`, `phpstan.neon*`, baseline files, CI workflows

If a step did not run (status `skipped` with reason `shared-database`, `no-browser-tests`, `plugin-missing`, `without`, `gate-aborted`, or status `error`), judge statically from the test code and say so under Limitations.

## Scope

**In:** `tests/`, test configuration, test helpers and traits, factories and seeders as used by tests, browser tests, PHPStan configuration, baseline and results (for the whole codebase), CI quality gates.

**Out:** Security or correctness of application code as such (backend and frontend agents). But a **missing or weak test for a security-critical path is your finding**, and so is a bug proven by a failing test or by PHPStan.

## Checklist

### 1. Test results
- Failing tests: classify the root cause as *app bug*, *broken/outdated test* or *environment*. Every failure group needs a classification in the "Test results" section. A failure that reveals an application bug is a `test-failure` finding with the app location **and** the test location.
- Skipped, incomplete and todo tests: how many, why, and are they stale? (`skipped-test`)
- Slowest tests and suspicious durations (sleep, real network, heavy seeding). (`slow-test`)

### 2. Coverage map
- Map routes and key classes (controllers, actions/services, policies, jobs, commands, listeners, Livewire components, mailables/notifications) to the tests that cover them, using `coverage-by-class.json` and `route-test-map.json`.
- Flag untested critical paths: authentication (login, registration, password reset, 2FA, email verification), authorization (policies, gates), payments and billing, data export and deletion, file uploads, admin area, webhooks, scheduled jobs. (`untested-critical-path`)
- Coverage percentages are secondary. A 95 % covered class without meaningful assertions on outcomes is still untested.

### 3. Test quality
- Tests without assertions, or only `assertOk()` / `assertStatus(200)` on endpoints that return or change data. (`weak-assertion`)
- Testing implementation instead of behavior; mocking Eloquent or the class under test. (`implementation-coupling`)
- Missing fakes where side effects happen: `Http::fake()` / `preventStrayRequests()`, `Mail`, `Notification`, `Queue`, `Bus`, `Event`, `Storage`. Check `tests/Pest.php` and base test cases for global fakes first. (`missing-fake`)
- Flakiness sources: time without `freezeTime()` / `travelTo()`, unseeded randomness, order dependence, shared static state, missing `RefreshDatabase` / `LazilyRefreshDatabase`. (`flaky-test`)
- Factory quality, duplicate or dead tests, Feature vs Unit split, Pest datasets, arch tests. (`factory-quality`, `duplicate-test`, `test-structure`)

### 4. Security tests
- Authorization: for every policy and protected route, is there a test proving that another user or a guest receives 403/404 (IDOR)? (`missing-authz-test`)
- Validation: invalid types, boundary values, oversized payloads, extra fields (mass assignment attempts are ignored). (`missing-validation-test`)
- Rate limiting on auth and forms, CSRF on web forms, signed URLs, email verification gates, tenant isolation. (`missing-security-test`)

### 5. Edge cases
For each critical feature, check whether tests cover: empty/null, boundaries (0, −1, max), unicode and emoji, very long strings, timezones and DST, double submit / concurrency, soft-deleted models, pagination past the last page, locales.
Report **concrete missing test cases** (suggested test names and a one-line sketch), not generic advice. (`missing-edge-case`)

### 6. Browser tests ({{browser_tests}})
- If browser tests ran: classify every failure like in section 1 (a failure revealing an app bug is `test-failure` with the browser test as one location). Look at the screenshots of failed tests.
- Are the critical user journeys covered end to end (login, core create/update flow, checkout or equivalent)? Compare the visited URIs with `routes.json`. (`browser-coverage`)
- Waiting strategy: fixed pauses instead of waiting for elements, retries, timeouts → `flaky-test`.
- Selector robustness, isolation and database reset between tests, JavaScript console errors asserted, mobile viewport.
- If there are no browser tests (`no-browser-tests`): one `browser-coverage` finding (severity depends on how UI-heavy the app is) plus the top 5 journeys to cover first.

### 7. PHPStan (level {{phpstan_level}})
- Work from `phpstan-grouped.json`. Summarize by identifier; do not list every error.
- Separate **real bugs** (possible null dereference, wrong argument types, undefined methods or properties, unreachable code, wrong return types in critical code) from **typing debt** (`missingType.*`, `mixed` from array access, generics).
- Real bugs: individual `phpstan-bug` findings, category `correctness`, with the app location.
- Typing debt: one grouped `phpstan-debt` finding per identifier with count, top files and an effort estimate.
- Baseline: errors marked `baselined` are known errors the project hides via its baseline. Report them as **exactly one** grouped `phpstan-baseline` finding (count, top identifiers, top files). Exception: a baselined error that is a real bug in security-critical code gets its own `phpstan-bug` finding.
- Level {{phpstan_level}} is the project's own choice and is **not** a finding. At most one `info` finding estimating the effort to reach the next level.
- Configuration: excluded paths (is `tests/` analysed?), broad `ignoreErrors` patterns. (`phpstan-config`)

### 8. Tooling & CI
- Do tests, PHPStan and Pint run in CI? Parallel testing? Coverage minimum? Pest arch or type-coverage checks? (`ci-quality-gate`)

### 9. Custom checks
{{custom_checks}}

## Required findings

The harness checks that your report covers these facts when they apply:

- Failing tests → at least one `test-failure` finding.
- Failing browser tests (without `environment_error`) → at least one `test-failure` finding with a browser test as location.
- Baselined PHPStan errors → one `phpstan-baseline` finding.

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Test results** — table: Failure group (tests and browser tests) · Tests · Classification (app bug / broken test / environment) · Finding ID. Write "All tests passed." or the skip reason if there is nothing to classify.
- **Coverage map** — table: Area · Routes/Classes · Tests · Status (✅ covered / ⚠️ weak / ❌ missing).
- **Top 10 missing tests** — suggested Pest test names with a one-line sketch each.
- **PHPStan overview** — table: Identifier · Count · Real bug or debt · Top file.
