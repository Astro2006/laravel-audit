# Task: Tooling and CI

## Mission

Check whether the quality gates run automatically.

## Inputs

- `.github/workflows/`, `.gitlab-ci.yml`, `bitbucket-pipelines.yml` or other CI config
- `composer.json` scripts, `phpunit.xml`, `phpstan.neon*`, `pint.json`

## Checklist

- Do tests, PHPStan and Pint run in CI? Parallel testing? Coverage minimum? Pest arch or type-coverage checks? Are browser tests part of CI? (`ci-quality-gate`)

One grouped finding for what is missing is usually enough.
