<?php

/*
| Tests & Static Analysis Audit (TST): its tasks and the checks each task owns.
|
| Every check belongs to exactly one task. A task is one agent session
| with its own prompt in tasks/<task>.md next to this file.
| Publish with `php artisan vendor:publish --tag=audit-checks` to change
| checks or leave tasks out for one project.
|
| depends_on: tasks whose sections this task gets as context.
| sections: report sections the task writes.
| snapshots: preflight data in the prompt (the status of all steps is always there).
| stage: analyse, standards or security (default standards); the order an
|   orchestrating agent works in. See docs/MVP3.md.
*/

return [
    // Audit-specific report sections, in report order.
    'sections' => ['Test results', 'Coverage map', 'Top 10 missing tests', 'PHPStan overview'],

    // Sections the summary task writes from the merged findings.
    'summary_sections' => ['Top 10 missing tests'],

    'tasks' => [
        'results' => [
            'title' => 'Test results',
            'stage' => 'analyse',
            'sections' => ['Test results'],
            'snapshots' => ['tests', 'browser'],
            'checks' => [
                'test-failure' => 'A failing test or browser test; name the app location if it reveals an app bug.',
                'skipped-test' => 'Skipped, incomplete or todo tests, especially stale ones.',
                'slow-test' => 'Suspiciously slow tests (sleep, real network, heavy seeding).',
            ],
        ],
        'coverage' => [
            'title' => 'Coverage map',
            'stage' => 'analyse',
            'sections' => ['Coverage map'],
            'snapshots' => ['coverage', 'route-tests', 'browser'],
            'checks' => [
                'untested-critical-path' => 'A critical path (auth, authorization, payments, uploads, admin, webhooks, jobs) without meaningful tests.',
                'browser-coverage' => 'Critical user journeys without browser tests, or weak browser tests.',
            ],
        ],
        'gaps' => [
            'title' => 'Missing security and edge-case tests',
            'stage' => 'security',
            'snapshots' => ['route-tests', 'routes'],
            'checks' => [
                'missing-authz-test' => 'No test proving another user or a guest gets 403/404 for a protected resource.',
                'missing-validation-test' => 'No tests for invalid input, boundaries or mass-assignment attempts.',
                'missing-security-test' => 'No tests for rate limiting, CSRF, signed URLs, verification gates or tenant isolation.',
                'missing-edge-case' => 'A concrete missing edge case for a critical feature.',
            ],
        ],
        'quality' => [
            'title' => 'Test quality',
            'stage' => 'standards',
            'checks' => [
                'weak-assertion' => 'Tests without assertions or only status assertions on endpoints that change data.',
                'implementation-coupling' => 'Tests that test implementation instead of behaviour, or mock Eloquent / the class under test.',
                'missing-fake' => 'Side effects without Http/Mail/Notification/Queue/Bus/Event/Storage fakes.',
                'flaky-test' => 'Sources of flakiness: time, randomness, order dependence, shared state, fixed pauses in browser tests.',
            ],
        ],
        'suite' => [
            'title' => 'Test suite structure',
            'stage' => 'standards',
            'checks' => [
                'factory-quality' => 'Unrealistic factory defaults, missing states, huge object graphs.',
                'duplicate-test' => 'Duplicate tests, tests for removed code, commented-out tests.',
                'test-structure' => 'Feature/unit split, datasets that would remove duplication, missing arch tests.',
            ],
        ],
        'phpstan' => [
            'title' => 'PHPStan',
            'stage' => 'analyse',
            'sections' => ['PHPStan overview'],
            'snapshots' => ['phpstan'],
            'checks' => [
                'phpstan-bug' => 'A real bug PHPStan reports (null dereference, wrong types, undefined members, unreachable code).',
                'phpstan-debt' => 'Typing debt of one PHPStan identifier, grouped.',
                'phpstan-baseline' => 'Errors hidden in the PHPStan baseline, as one grouped finding.',
                'phpstan-config' => 'PHPStan configuration issues: excluded paths, broad ignoreErrors.',
            ],
        ],
        'tooling' => [
            'title' => 'Tooling and CI',
            'stage' => 'analyse',
            'checks' => [
                'ci-quality-gate' => 'Tests, PHPStan or Pint missing from CI, no coverage minimum.',
            ],
        ],
    ],
];
