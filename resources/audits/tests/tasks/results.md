# Task: Test results

## Mission

Classify every failing test and look at skipped and slow tests. You judge results and test code; you run nothing.

## Inputs

- The Tests snapshot above — totals and one row per failure group; the detail file of a group has the message and the failing tests. Its `skipped` and `slow` files list skipped tests with reasons and the slowest tests
- The Browser snapshot above — the same for browser tests, plus screenshots of failures (browser tests: {{browser_tests}})

If a step did not run (`skipped` with a reason, or `error`), say so under Limitations and in the section.

## Checklist

- Failing tests and failing browser tests: classify each failure group as *app bug*, *broken/outdated test* or *environment*. A failure that reveals an application bug is a `test-failure` finding with the app location **and** the test location (for browser tests: the browser test file). (`test-failure`)
- Skipped, incomplete and todo tests: how many, why, are they stale? (`skipped-test`)
- Slowest tests and suspicious durations (sleep, real network, heavy seeding). (`slow-test`)

## Required findings

The harness rejects your report unless: failing tests → at least one `test-failure` finding; failing browser tests without an environment error → at least one `test-failure` finding with a browser test as location.

## Section you write

- `## Test results` — table: Failure group (tests and browser tests) · Tests · Classification (app bug / broken test / environment) · Finding ID. Write "All tests passed." or the skip reason if there is nothing to classify.
