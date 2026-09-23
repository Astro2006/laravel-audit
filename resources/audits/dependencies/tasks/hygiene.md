# Task: Constraints, Composer config and licenses

## Mission

Check version constraints, the lock file, Composer configuration and licenses.

## Inputs

- The Composer snapshot above — everything Composer can tell, precomputed, one row per fact (`Kind` = advisories, abandoned, outdated_major, unused_candidates …). The detail file of a row adds the package's usage: namespaces, reference counts (production code vs `tests/`), top files, auto-discovered providers/aliases, binaries used in scripts/CI
- `composer.json`, `composer.lock`

You cannot run Composer. If a precomputed file is missing, say so under Limitations. npm packages are not part of this audit.
- The `composer` file of the Composer snapshot: `composer validate` output and Composer config

## Checklist

- Constraints from `constraints_flagged`: `*`, `dev-*` branches, `>=` without an upper bound, pins so tight they block security patches; `minimum-stability: dev` without `prefer-stable: true`. (`loose-constraint`)
- `composer.lock` committed and in sync with `composer.json` (`validate`). (`lock-out-of-sync`)
- `config.allow-plugins` set to `true` for everything; custom repositories (`vcs`, `path`, `artifact`, private mirrors) and their trust level; composer scripts that download or execute remote code; `config.platform` not matching the production PHP version. (`composer-config`)
- Large `files` autoloads, classmap bloat. (`autoload-bloat`)
- Licenses from the `licenses_flagged` rows: copyleft licenses (GPL, AGPL) in a proprietary or commercial project (compare with the root `license` in `composer.json`; if none is set, note the assumption), packages with unknown or missing licenses. (`license-risk`)

## Required findings

An out-of-sync lock file (`validate.lock_in_sync = false`) → the harness requires a `lock-out-of-sync` finding.
