# Task: Test results

## Mission

Classify every failing test and look at skipped and slow tests. You judge results and test code; you run nothing.

## Inputs

- `{{preflight_dir}}/summary.json` — status of the tests and browser steps, skip reasons
- `{{preflight_dir}}/tests/tests-condensed.json` — totals, failures grouped by exception, skipped tests with reasons, 10 slowest tests
- `{{preflight_dir}}/tests/browser-condensed.json` and `{{preflight_dir}}/tests/browser-artifacts/` — browser results and screenshots of failures (browser tests: {{browser_tests}})
- Raw files only for details: `tests/junit.xml`, `tests/browser-junit.xml`

If a step did not run (`skipped` with a reason, or `error`), say so under Limitations and in the section.

## Checklist

- Failing tests and failing browser tests: classify each failure group as *app bug*, *broken/outdated test* or *environment*. A failure that reveals an application bug is a `test-failure` finding with the app location **and** the test location (for browser tests: the browser test file). (`test-failure`)
- Skipped, incomplete and todo tests: how many, why, are they stale? (`skipped-test`)
- Slowest tests and suspicious durations (sleep, real network, heavy seeding). (`slow-test`)

## Required findings

The harness rejects your report unless: failing tests → at least one `test-failure` finding; failing browser tests without `environment_error` → at least one `test-failure` finding with a browser test as location.

## Section you write

- `## Test results` — table: Failure group (tests and browser tests) · Tests · Classification (app bug / broken test / environment) · Finding ID. Write "All tests passed." or the skip reason if there is nothing to classify.
