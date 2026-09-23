# Task: Test suite structure

## Mission

Judge how the test suite is organized and how it builds its test data.

## Inputs

- `tests/` directory layout, `tests/Pest.php`, `phpunit.xml`, `database/factories`

## Checklist

- Factories with unrealistic defaults, missing states for common cases, or huge object graphs per test. (`factory-quality`)
- Duplicate tests, or tests that no longer test anything that exists. (`duplicate-test`)
- Feature vs Unit split, Pest datasets where tests repeat, arch tests, naming and grouping. (`test-structure`)
