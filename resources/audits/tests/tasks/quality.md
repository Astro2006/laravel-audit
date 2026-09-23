# Task: Test quality

## Mission

Judge whether the existing tests assert outcomes and are isolated and deterministic.

## Inputs

- `tests/Pest.php`, base test cases, `phpunit.xml`
- A few representative tests per area (Feature, Unit, Browser if present), not the whole suite

## Checklist

- Tests without assertions, or only `assertOk()` / `assertStatus(200)` on endpoints that return or change data. (`weak-assertion`)
- Testing implementation instead of behaviour; mocking Eloquent or the class under test. (`implementation-coupling`)
- Missing fakes where side effects happen: `Http::fake()` / `preventStrayRequests()`, `Mail`, `Notification`, `Queue`, `Bus`, `Event`, `Storage`. Check `tests/Pest.php` and base test cases for global fakes first. (`missing-fake`)
- Flakiness: time without `freezeTime()` / `travelTo()`, unseeded randomness, order dependence, shared static state, missing `RefreshDatabase` / `LazilyRefreshDatabase`; in browser tests fixed pauses instead of waiting for elements. (`flaky-test`)
