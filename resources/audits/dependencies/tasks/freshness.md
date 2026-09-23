# Task: Freshness and support

## Mission

Check whether the runtime and the direct dependencies are supported and current, and plan the upgrades.

## Inputs

- The Composer snapshot above: one row per fact (`Kind` = advisories, abandoned, outdated_major, unused_candidates …). A row's detail file adds where the package is used
- `composer.json`, `composer.lock`
- The `time` field of packages in `composer.lock`

You cannot run Composer; a missing precomputed file goes under Limitations. npm packages are not part of this audit.

## Checklist

- Laravel and PHP: is the installed major still receiving bug and security fixes? Is the PHP version in `composer.json` / `config.platform` still supported? If you cannot verify support windows, say so. (`unsupported-runtime`)
- Direct dependencies one or more majors behind. (`outdated-major`)
- Packages whose latest release is very old (use the `time` field in `composer.lock`). (`stale-package`)

## Section you write

- `## Upgrade plan` — ordered steps: security fixes first (from the advisories in the Composer snapshot), then safe patch/minor updates grouped, then majors with breaking-change notes and effort.
