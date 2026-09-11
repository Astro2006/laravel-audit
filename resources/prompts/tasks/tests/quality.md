# Task: Test quality

## Mission

Judge how good the existing tests are: do they assert outcomes, are they isolated and deterministic, is the suite well structured?

## Inputs

- `tests/`, `tests/Pest.php`, base test cases, `phpunit.xml`, `database/factories`
- Browser tests if present

## Checklist

- Tests without assertions, or only `assertOk()` / `assertStatus(200)` on endpoints that return or change data. (`weak-assertion`)
- Testing implementation instead of behaviour; mocking Eloquent or the class under test. (`implementation-coupling`)
- Missing fakes where side effects happen: `Http::fake()` / `preventStrayRequests()`, `Mail`, `Notification`, `Queue`, `Bus`, `Event`, `Storage`. Check `tests/Pest.php` and base test cases for global fakes first. (`missing-fake`)
- Flakiness: time without `freezeTime()` / `travelTo()`, unseeded randomness, order dependence, shared static state, missing `RefreshDatabase` / `LazilyRefreshDatabase`; in browser tests fixed pauses instead of waiting for elements. (`flaky-test`)
- Factory quality, duplicate or dead tests, Feature vs Unit split, Pest datasets, arch tests. (`factory-quality`, `duplicate-test`, `test-structure`)
