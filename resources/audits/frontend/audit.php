<?php

/*
| Frontend, Routes & UI/UX Audit (FE): its tasks and the checks each task owns.
|
| Every check belongs to exactly one task. A task is one agent session
| with its own prompt in tasks/<task>.md next to this file.
| Publish with `php artisan vendor:publish --tag=audit-checks` to change
| checks or leave tasks out for one project.
|
| depends_on: tasks whose sections this task gets as context.
| sections: report sections the task writes.
| snapshots: preflight data in the prompt (the status of all steps is always there).
*/

return [
    // Audit-specific report sections, in report order.
    'sections' => ['Frontend map', 'Route risk table', 'UI consistency matrix'],

    'tasks' => [
        'map' => [
            'title' => 'Frontend map',
            'sections' => ['Frontend map'],
            'snapshots' => ['routes'],
            'checks' => [],
        ],
        'access' => [
            'title' => 'Route access control',
            'sections' => ['Route risk table'],
            'snapshots' => ['routes'],
            'checks' => [
                'route-authorization' => 'A route or controller without auth / policy / can, or a Form Request whose authorize() returns true without other checks.',
                'idor' => 'Route model binding without an ownership check.',
                'signed-url' => 'Unsubscribe, download or invitation links without signed URLs.',
                'webhook-signature' => 'A webhook endpoint without signature verification.',
            ],
        ],
        'routes' => [
            'title' => 'Route hygiene',
            'snapshots' => ['routes'],
            'checks' => [
                'state-changing-get' => 'A GET route that changes state.',
                'missing-throttle' => 'Login, registration, password reset, forms or API without throttling.',
                'csrf-exclusion' => 'An unjustified CSRF exclusion.',
                'debug-route' => 'Debug or tooling routes reachable without a gate.',
                'broken-route' => 'Routes pointing to missing controllers or methods, duplicate or conflicting URIs.',
            ],
        ],
        'injection' => [
            'title' => 'Rendering, redirects and uploads',
            'snapshots' => ['code-candidates'],
            'checks' => [
                'xss' => 'Unescaped output of untrusted data in Blade, Vue, React, Alpine or JS.',
                'open-redirect' => 'Redirects to request-controlled URLs, header injection, TrustProxies/TrustHosts.',
                'file-upload' => 'Upload validation, public storage, trusted client file names, traversal in downloads.',
            ],
        ],
        'client' => [
            'title' => 'Livewire, Inertia and client secrets',
            'checks' => [
                'livewire-security' => 'Livewire properties that must be locked, missing authorization in actions, unsafe uploads.',
                'inertia-props-leak' => 'Inertia shared data or props leaking models, permissions or config.',
                'client-secret' => 'Secrets in VITE_* variables, JS, source maps or HTML comments.',
            ],
        ],
        'config' => [
            'title' => 'Headers, session and CORS',
            'checks' => [
                'security-headers' => 'Missing CSP, frame-ancestors or similar headers.',
                'session-cookie-config' => 'Insecure session or cookie settings.',
                'cors-config' => 'Overly permissive CORS, e.g. * with credentials.',
            ],
        ],
        'errors' => [
            'title' => 'Error and state handling',
            'snapshots' => ['browser'],
            'checks' => [
                'error-handling-ui' => 'Inconsistent validation errors, missing loading/empty/error states, double submits.',
                'error-pages' => 'Missing custom error pages, stack traces or exception messages shown to users.',
            ],
        ],
        'ui' => [
            'title' => 'UI consistency',
            'depends_on' => ['map'],
            'sections' => ['UI consistency matrix'],
            'snapshots' => ['translations'],
            'checks' => [
                'ui-inconsistency' => 'The same UI element implemented several ways.',
                'design-tokens' => 'Arbitrary values, inline styles or inconsistent colors, spacing and typography.',
                'i18n' => 'Hardcoded user-facing strings or missing translation keys.',
                'responsiveness' => 'Fixed widths, unscrollable tables, tiny touch targets.',
            ],
        ],
        'a11y' => [
            'title' => 'Accessibility',
            'depends_on' => ['map'],
            'checks' => [
                'accessibility' => 'WCAG 2.2 AA issues visible in the markup.',
            ],
        ],
        'performance' => [
            'title' => 'Frontend performance',
            'checks' => [
                'asset-performance' => 'Unoptimized images and assets, huge imports.',
                'view-query' => 'Lazy loading triggered from Blade or Livewire views.',
            ],
        ],
        'dead-code' => [
            'title' => 'Dead frontend code',
            'checks' => [
                'dead-frontend-code' => 'Unused views, components, JS/CSS files or translation keys.',
            ],
        ],
    ],
];
