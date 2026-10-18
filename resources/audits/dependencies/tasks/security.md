# Task: Advisories and abandoned packages

## Mission

Turn the security advisories and abandoned packages into prioritized, reachability-aware findings.

## Inputs

- The Composer snapshot above: one row per fact (`Kind` = advisories, abandoned, outdated_major, unused_candidates …). A row's detail file adds where the package is used
- `composer.json`, `composer.lock`

You cannot run Composer; a missing precomputed file goes under Limitations. npm packages are not part of this audit.

## Checklist

- **Vulnerabilities:** one finding per affected package, listing every advisory for it: installed version, affected range, CVE/GHSA ID, fixed version, direct or transitive (with the direct dependency that pulls it in), and **reachability**: is the affected component or feature used in this app? Check with the usage map and a targeted code search. Start from the advisory severity: reachable in production → keep or raise; dev-only → usually lower; unreachable → lower but still report. Recommend the minimal safe upgrade and note breaking changes. (`vulnerable-dependency`)
- **Abandoned packages:** one finding per abandoned package with the suggested replacement (if the maintainer named one) and the effort to migrate. (`abandoned-package`)

## Required findings

The harness rejects your report unless every package in `advisories` has a `vulnerable-dependency` finding and every package in `abandoned` has an `abandoned-package` finding, each with `package:<name>` in its locations.
