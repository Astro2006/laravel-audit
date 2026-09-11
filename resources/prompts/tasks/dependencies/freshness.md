# Task: Freshness and support

## Mission

Check whether the runtime and the direct dependencies are supported and current, and plan the upgrades.

## Inputs

- `{{preflight_dir}}/composer/dependency-facts.json` — everything Composer can tell, precomputed: read it first
- `{{preflight_dir}}/composer/usage-map.json` — per direct package: namespaces, reference counts (production code vs `tests/`), top files, auto-discovered providers/aliases, binaries used in scripts/CI
- `composer.json`, `composer.lock`

You cannot run Composer. If a precomputed file is missing, say so under Limitations. npm packages are not part of this audit.
- Raw: `{{preflight_dir}}/composer/outdated.json`; the `time` field of packages in `composer.lock`

## Checklist

- Laravel and PHP: is the installed major still receiving bug and security fixes? Is the PHP version in `composer.json` / `config.platform` still supported? If you cannot verify support windows, say so. (`unsupported-runtime`)
- Direct dependencies one or more majors behind. (`outdated-major`)
- Packages whose latest release is very old (use the `time` field in `composer.lock`). (`stale-package`)

## Section you write

- `## Upgrade plan` — ordered steps: security fixes first (from the advisories in `dependency-facts.json`), then safe patch/minor updates grouped, then majors with breaking-change notes and effort.
