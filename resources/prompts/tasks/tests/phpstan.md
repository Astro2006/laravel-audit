# Task: PHPStan

## Mission

Turn the PHPStan results into real bugs and a debt overview. The project's level ({{phpstan_level}}) is its own choice and not a finding.

## Inputs

- `{{preflight_dir}}/phpstan/phpstan-grouped.json` — errors grouped by identifier and file; baselined errors are marked `baselined`
- `phpstan.neon*`, baseline files
- Raw `{{preflight_dir}}/phpstan/phpstan.json` only for details

## Checklist

- Summarize by identifier; do not list every error.
- **Real bugs** (possible null dereference, wrong argument types, undefined methods or properties, unreachable code, wrong return types in critical code): individual `phpstan-bug` findings, category `correctness`, with the app location. Open the code to confirm; errors caused by missing framework stubs (Eloquent magic, Pest `$this`) are not bugs. "Undefined property" or "access to property" on a model usually means a missing `@property` annotation: check casts, accessors and `selectRaw` aliases first. Use `confirmed` only when you traced the runtime failure in the code; otherwise it is typing debt. (`phpstan-bug`)
- **Typing debt** (`missingType.*`, `mixed` from array access, generics): one grouped `phpstan-debt` finding per identifier with count, top files and an effort estimate. (`phpstan-debt`)
- **Baseline:** errors marked `baselined` are exactly one grouped `phpstan-baseline` finding (count, top identifiers, top files). Exception: a baselined real bug in security-critical code gets its own `phpstan-bug` finding. (`phpstan-baseline`)
- Configuration: excluded paths, broad `ignoreErrors` patterns, missing Larastan extension. At most one `info` finding estimating the effort to reach the next level. (`phpstan-config`)

## Required findings

Baselined PHPStan errors → the harness requires one `phpstan-baseline` finding.

## Section you write

- `## PHPStan overview` — table: Identifier · Count · Real bug or debt · Top file.
