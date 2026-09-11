# Audit: Dependencies (Composer)

`audit_key: dependencies` · `id_prefix: DEP`

## Mission

Determine for every direct Composer dependency: is it secure, is it needed, is it in the right place (`require` vs `require-dev`), is it maintained and up to date, and is its license acceptable. Turn `composer audit` output into prioritized, reachability-aware findings and a concrete upgrade plan.

## Inputs

- `composer.json`, `composer.lock`
- `{{preflight_dir}}/composer/audit.json` — `composer audit --format=json --locked` (advisories + abandoned packages)
- `{{preflight_dir}}/composer/why.json` — `composer why` for each affected package (which direct dependency pulls it in)
- `{{preflight_dir}}/composer/show.json` — installed direct dependencies
- `{{preflight_dir}}/composer/outdated.json` — latest versions of direct dependencies
- `{{preflight_dir}}/composer/licenses.json`
- `{{preflight_dir}}/composer/validate.txt` — `composer validate` output (lock in sync?)
- `{{preflight_dir}}/composer/usage-map.json` — per direct package: namespaces, reference counts (production code vs `tests/`), top files, auto-discovered providers/aliases, binaries used in scripts/CI
- `{{preflight_dir}}/npm-audit.json` — only if present

If a precomputed file is missing, you may run **read-only** composer commands (`composer why`, `composer show`, `composer depends`). Never run `install`, `update`, `require` or `remove`.

## Scope

**In:** Composer dependencies, constraints, Composer configuration and supply-chain settings, framework/PHP support status. npm packages only if `npm-audit.json` exists.

**Out:** How application code uses a library internally (backend/frontend agents), unless it determines whether a vulnerability is reachable.

## Checklist

### 1. Vulnerabilities
For each advisory: package, installed version, affected range, CVE/GHSA ID, fixed version, direct or transitive (via `why.json`), and **reachability**: is the affected component or feature actually used in this app? Check with the usage map and targeted code search.
Start from the advisory severity and adjust: reachable in production → keep or raise; dev-only and not part of the production build → usually lower; unreachable → lower but still report. Recommend the minimal safe upgrade and note breaking changes.

### 2. Abandoned packages
Report each abandoned package with the suggested replacement (if the maintainer named one) and the effort to migrate.

### 3. Necessity — is it needed?
- **Unused:** zero references in the usage map **and** no implicit usage. Implicit usage includes auto-discovered service providers, facades, middleware, Blade directives, Artisan commands, config files, binaries in composer scripts / CI / package.json. Packages like `laravel/tinker`, `laravel/sail`, `laravel/pail`, `laravel/pint`, `larastan/larastan`, `nunomaduro/collision`, Pest plugins, IDE helpers and debug bars are used implicitly; do not flag them as unused for lack of imports.
- **Misplaced:** a `require-dev` package used in production code (breaks with `composer install --no-dev`) → high. A `require` package used only in tests or tooling → move to `require-dev`.
- **Shadow dependencies:** production code uses a package that is only installed transitively → declare it explicitly.
- **Redundant or superseded:** several packages for the same job (two HTTP, PDF, image or date libraries), packages replaced by framework features in this Laravel version (verify with Boost docs search), polyfills for PHP versions below the project's minimum.

### 4. Freshness & support
- Laravel and PHP: is the installed major still receiving bug and security fixes? Is the PHP version in `composer.json` / `config.platform` still supported? Verify support windows via Boost docs search where possible; if you cannot verify, say so.
- Direct dependencies one or more majors behind; packages whose latest release is very old (use the `time` field in `composer.lock`).

### 5. Constraints & Composer hygiene
- Constraints: `*`, `dev-*` branches, `>=` without an upper bound, pins so tight they block security patches; `minimum-stability: dev` without `prefer-stable: true`.
- `composer.lock` committed and in sync with `composer.json` (`validate.txt`).
- `config.allow-plugins` set to `true` for everything; custom repositories (`vcs`, `path`, `artifact`, private mirrors) and their trust level; composer scripts that download or execute remote code; `config.platform` not matching the production PHP version.
- Autoload: large `files` autoloads, classmap bloat.

### 6. Licenses
- Copyleft licenses (GPL, AGPL) in a proprietary or commercial project (compare with the root `license` in `composer.json`; if none is set, note the assumption).
- Packages with unknown or missing licenses.

### 7. Custom checks
{{custom_checks}}

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Dependency inventory** — every direct dependency: Package · prod/dev · Installed · Latest · Used in (prod refs / test refs) · Verdict (keep / upgrade / move / remove / replace) · Notes.
- **Upgrade plan** — ordered steps: security fixes first, then safe patch/minor updates grouped, then majors with breaking-change notes and effort.
