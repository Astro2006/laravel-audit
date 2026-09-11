# Task: Advisories and abandoned packages

## Mission

Turn the security advisories and abandoned packages into prioritized, reachability-aware findings.

## Inputs

- `{{preflight_dir}}/composer/dependency-facts.json` — everything Composer can tell, precomputed: read it first
- `{{preflight_dir}}/composer/usage-map.json` — per direct package: namespaces, reference counts (production code vs `tests/`), top files, auto-discovered providers/aliases, binaries used in scripts/CI
- `composer.json`, `composer.lock`

You cannot run Composer. If a precomputed file is missing, say so under Limitations. npm packages are not part of this audit.
- Raw: `{{preflight_dir}}/composer/audit.json`, `why.json`

## Checklist

- **Vulnerabilities:** one finding per affected package, listing every advisory for it: installed version, affected range, CVE/GHSA ID, fixed version, direct or transitive (with the direct dependency that pulls it in), and **reachability**: is the affected component or feature used in this app? Check with the usage map and a targeted code search. Start from the advisory severity: reachable in production → keep or raise; dev-only → usually lower; unreachable → lower but still report. Recommend the minimal safe upgrade and note breaking changes. (`vulnerable-dependency`)
- **Abandoned packages:** one finding per abandoned package with the suggested replacement (if the maintainer named one) and the effort to migrate. (`abandoned-package`)

## Required findings

The harness rejects your report unless every package in `advisories` has a `vulnerable-dependency` finding and every package in `abandoned` has an `abandoned-package` finding, each with `package:<name>` in its locations.
