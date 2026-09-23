# Task: Necessity and placement

## Mission

Decide for every direct dependency whether it is needed and in the right place.

## Inputs

- The Composer snapshot above — everything Composer can tell, precomputed, one row per fact (`Kind` = advisories, abandoned, outdated_major, unused_candidates …). The detail file of a row adds the package's usage: namespaces, reference counts (production code vs `tests/`), top files, auto-discovered providers/aliases, binaries used in scripts/CI
- `composer.json`, `composer.lock`

You cannot run Composer. If a precomputed file is missing, say so under Limitations. npm packages are not part of this audit.

## Checklist

- **Unused:** confirm or reject each `unused_candidates` row. Implicit usage counts: auto-discovered service providers, facades, middleware, Blade directives, Artisan commands, config files, binaries in composer scripts / CI / package.json, global helper functions. Packages like `laravel/tinker`, `laravel/sail`, `laravel/pail`, `laravel/pint`, `larastan/larastan`, `nunomaduro/collision`, Pest plugins, IDE helpers, debug bars and `astro2006/laravel-audit` are used implicitly. (`unused-package`)
- **Misplaced:** confirm `misplaced_candidates`. A `require-dev` package used in production code breaks with `composer install --no-dev` → high. A `require` package used only in tests or tooling → move to `require-dev`. (`misplaced-package`)
- **Shadow dependencies:** confirm `shadow_candidates` — production code uses a package that is only installed transitively → declare it. (`shadow-dependency`)
- **Redundant or superseded:** several packages for the same job, packages replaced by framework features in this Laravel version, polyfills for PHP versions below the project's minimum. (`redundant-package`)

Rejected candidates are not findings; list them in the inventory with the reason.

## Section you write

- `## Dependency inventory` — every direct dependency: Package · prod/dev · Installed · Latest · Used in (prod refs / test refs) · Verdict (keep / upgrade / move / remove / replace) · Notes.
