# Task: Coverage map

## Mission

Map what the tests cover and find critical paths without meaningful tests.

## Inputs

- `{{preflight_dir}}/tests/coverage-by-class.json` — line coverage per class in `app/`, uncovered methods, lowest first (may be missing; see `summary.json`)
- `{{preflight_dir}}/tests/route-test-map.json` — per route, the test files that reference it (heuristic; verify)
- `{{preflight_dir}}/tests/browser-condensed.json` — visited URIs per browser test file (browser tests: {{browser_tests}})
- `{{preflight_dir}}/routes.json`

## Checklist

- Map routes and key classes (controllers, actions/services, policies, jobs, commands, listeners, Livewire components, mailables/notifications) to the tests that cover them.
- Untested critical paths: authentication (login, registration, password reset, 2FA, email verification), authorization (policies, gates), payments and billing, data export and deletion, file uploads, admin area, webhooks, scheduled jobs. Coverage percentages are secondary: a covered class without meaningful assertions on outcomes is still untested. (`untested-critical-path`)
- Browser coverage: are the critical user journeys covered end to end? Compare the visited URIs with `routes.json`. Without browser tests: one `browser-coverage` finding (severity by how UI-heavy the app is) plus the top 5 journeys to cover first. (`browser-coverage`)

## Section you write

- `## Coverage map` — table: Area · Routes/Classes · Tests · Status (✅ covered / ⚠️ weak / ❌ missing).
