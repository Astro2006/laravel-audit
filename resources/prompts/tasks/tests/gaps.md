# Task: Missing security and edge-case tests

## Mission

Find concrete tests that are missing for security-critical behaviour and edge cases. Report test cases a developer can write right away.

## Inputs

- `{{preflight_dir}}/routes.json`, `{{preflight_dir}}/tests/route-test-map.json`
- `tests/`, `app/Policies`, Form Requests, the controllers of critical features

## Checklist

- Authorization: for every policy and protected route, is there a test proving that another user or a guest receives 403/404 (IDOR)? (`missing-authz-test`)
- Validation: invalid types, boundary values, oversized payloads, extra fields (mass assignment attempts are ignored). (`missing-validation-test`)
- Rate limiting on auth and forms, CSRF on web forms, signed URLs, email verification gates, tenant isolation. (`missing-security-test`)
- Edge cases for each critical feature: empty/null, boundaries (0, −1, max), unicode and emoji, very long strings, timezones and DST, double submit / concurrency, soft-deleted models, pagination past the last page, locales. (`missing-edge-case`)

Name concrete missing tests (suggested test name and a one-line sketch) in the Recommendation, not generic advice. Group by feature.
