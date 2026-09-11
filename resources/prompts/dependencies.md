# Audit: Dependencies (Composer)

`audit_key: dependencies` · `id_prefix: DEP`

## Mission

Determine for every direct Composer dependency: is it secure, is it needed, is it in the right place (`require` vs `require-dev`), is it maintained and up to date, and is its license acceptable. Turn the precomputed facts into prioritized, reachability-aware findings and a concrete upgrade plan.

## Inputs

Read `dependency-facts.json` first. Everything Composer can tell has already been computed; you add judgement.

- `{{preflight_dir}}/composer/dependency-facts.json` — advisories (package, installed version, affected range, advisory/CVE, severity, direct or transitive and via which package), abandoned packages with replacement, outdated majors, and **candidates** for misplaced, unused and shadow dependencies, flagged licenses, loose constraints, Composer config issues, `composer validate` result
- `{{preflight_dir}}/composer/usage-map.json` — per direct package: namespaces, reference counts (production code vs `tests/`), top files, auto-discovered providers/aliases, binaries used in scripts/CI
- `{{preflight_dir}}/composer/audit.json`, `show.json`, `outdated.json`, `licenses.json`, `why.json`, `validate.txt` — raw Composer output
- `composer.json`, `composer.lock`

You cannot run Composer. If a precomputed file is missing, say so under Limitations.

## Scope

**In:** Composer dependencies, constraints, Composer configuration and supply-chain settings, framework/PHP support status.

**Out:** How application code uses a library internally (backend/frontend agents), unless it determines whether a vulnerability is reachable. npm packages are not part of this audit yet.

## Checklist

### 1. Vulnerabilities (`vulnerable-dependency`)
One finding per affected package, listing every advisory for it: installed version, affected range, CVE/GHSA ID, fixed version, direct or transitive (with the direct dependency that pulls it in), and **reachability**: is the affected component or feature actually used in this app? Check with the usage map and targeted code search.
Start from the advisory severity and adjust: reachable in production → keep or raise; dev-only and not part of the production build → usually lower; unreachable → lower but still report. Recommend the minimal safe upgrade and note breaking changes.

### 2. Abandoned packages (`abandoned-package`)
One finding per abandoned package with the suggested replacement (if the maintainer named one) and the effort to migrate.

### 3. Necessity — is it needed?
- **Unused** (`unused-package`): confirm or reject each `unused_candidates` entry. Implicit usage counts: auto-discovered service providers, facades, middleware, Blade directives, Artisan commands, config files, binaries in composer scripts / CI / package.json, global helper functions (the usage map cannot see those). Packages like `laravel/tinker`, `laravel/sail`, `laravel/pail`, `laravel/pint`, `larastan/larastan`, `nunomaduro/collision`, Pest plugins, IDE helpers, debug bars and `astro2006/laravel-audit` are used implicitly; do not flag them as unused for lack of imports.
- **Misplaced** (`misplaced-package`): confirm `misplaced_candidates`. A `require-dev` package used in production code breaks with `composer install --no-dev` → high. A `require` package used only in tests or tooling → move to `require-dev`.
- **Shadow dependencies** (`shadow-dependency`): confirm `shadow_candidates` — production code uses a package that is only installed transitively → declare it explicitly.
- **Redundant or superseded** (`redundant-package`): several packages for the same job, packages replaced by framework features in this Laravel version, polyfills for PHP versions below the project's minimum.

### 4. Freshness & support
- Laravel and PHP: is the installed major still receiving bug and security fixes? Is the PHP version in `composer.json` / `config.platform` still supported? If you cannot verify support windows, say so. (`unsupported-runtime`)
- Direct dependencies one or more majors behind (`outdated-major`); packages whose latest release is very old — use the `time` field in `composer.lock` (`stale-package`).

### 5. Constraints & Composer hygiene
- Constraints from `constraints_flagged`: `*`, `dev-*` branches, `>=` without an upper bound, pins so tight they block security patches; `minimum-stability: dev` without `prefer-stable: true`. (`loose-constraint`)
- `composer.lock` committed and in sync with `composer.json` (`validate`). (`lock-out-of-sync`)
- `config.allow-plugins` set to `true` for everything; custom repositories (`vcs`, `path`, `artifact`, private mirrors) and their trust level; composer scripts that download or execute remote code; `config.platform` not matching the production PHP version. (`composer-config`)
- Large `files` autoloads, classmap bloat. (`autoload-bloat`)

### 6. Licenses (`license-risk`)
- Confirm `licenses_flagged`: copyleft licenses (GPL, AGPL) in a proprietary or commercial project (compare with the root `license` in `composer.json`; if none is set, note the assumption), packages with unknown or missing licenses.

### 7. Custom checks
{{custom_checks}}

## Required findings

The harness rejects the report unless it covers:

- every package in `advisories` with a `vulnerable-dependency` finding whose locations include `package:<name>`,
- every package in `abandoned` with an `abandoned-package` finding whose locations include `package:<name>`,
- an out-of-sync lock file (`validate.lock_in_sync = false`) with a `lock-out-of-sync` finding.

Rejected candidates are not findings; list them in the inventory with the reason.

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Dependency inventory** — every direct dependency: Package · prod/dev · Installed · Latest · Used in (prod refs / test refs) · Verdict (keep / upgrade / move / remove / replace) · Notes.
- **Upgrade plan** — ordered steps: security fixes first, then safe patch/minor updates grouped, then majors with breaking-change notes and effort.
