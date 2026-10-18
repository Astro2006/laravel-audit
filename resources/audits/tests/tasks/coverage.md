# Task: Coverage map

## Mission

Map what the tests cover and find critical paths without meaningful tests.

## Inputs

- The Coverage snapshot above — line coverage per class in `app/`, lowest first; the detail file of a class lists its uncovered methods (may be missing; see the preflight steps)
- The Route tests snapshot above — routes without a test (heuristic; verify); its `tested` file maps routes to test files
- The Browser snapshot above — its `visited` file lists the URIs per browser test file (browser tests: {{browser_tests}})

## Checklist

- Map routes and key classes (controllers, actions/services, policies, jobs, commands, listeners, Livewire components, mailables/notifications) to the tests that cover them.
- Untested critical paths: authentication (login, registration, password reset, 2FA, email verification), authorization (policies, gates), payments and billing, data export and deletion, file uploads, admin area, webhooks, scheduled jobs. Coverage percentages are secondary: a covered class without meaningful assertions on outcomes is still untested. (`untested-critical-path`)
- Browser coverage: are the critical user journeys covered end to end? Compare the visited URIs with the routes. Without browser tests: one `browser-coverage` finding (severity by how UI-heavy the app is) plus the top 5 journeys to cover first. (`browser-coverage`)

## Section you write

- `## Coverage map` — table: Area · Routes/Classes · Tests · Status (✅ covered / ⚠️ weak / ❌ missing).
