<?php

/*
| Backend Audit (BE): its tasks and the checks each task owns.
|
| Every check belongs to exactly one task. A task is one agent session
| with its own prompt in resources/prompts/agents/backend/<task>.md.
| Publish with `php artisan vendor:publish --tag=audit-checks` to change
| checks or leave tasks out for one project.
|
| depends_on: tasks whose sections this task gets as context.
| sections: report sections the task writes.
| snapshots: preflight data in the prompt (the status of all steps is always there).
*/

return [
    // Audit-specific report sections, in report order.
    'sections' => ['Conventions baseline', 'Convention scorecard', 'Dead code candidates'],

    'tasks' => [
        'conventions' => [
            'title' => 'Conventions baseline',
            'sections' => ['Conventions baseline', 'Convention scorecard'],
            'snapshots' => ['pint'],
            'checks' => [
                'naming-structure' => 'Naming and structure that break PSR-4 or Laravel conventions.',
                'code-style' => 'Pint rule violations, one grouped finding per relevant rule.',
            ],
        ],
        'laravel' => [
            'title' => 'Laravel conventions',
            'depends_on' => ['conventions'],
            'snapshots' => ['code-candidates'],
            'checks' => [
                'env-outside-config' => 'env() called outside config/.',
                'inline-validation' => 'Inline validation in controllers where the project uses Form Requests.',
            ],
        ],
        'structure' => [
            'title' => 'Structure and responsibilities',
            'depends_on' => ['conventions'],
            'snapshots' => ['routes'],
            'checks' => [
                'fat-controller' => 'Business logic in controllers, models or views; god classes.',
                'magic-values' => 'Magic strings where enums fit, hardcoded values that belong in config.',
            ],
        ],
        'data' => [
            'title' => 'Models and data exposure',
            'depends_on' => ['conventions'],
            'snapshots' => ['code-candidates'],
            'checks' => [
                'eloquent-hygiene' => 'Missing casts or relationship return types, mixed accessor styles, duplicated query logic, strict mode off.',
                'mass-assignment' => 'Unfiltered request data reaching fill(), create() or update(), in controllers or services.',
                'data-exposure' => 'Sensitive attributes not hidden, resources or toArray() exposing internals.',
            ],
        ],
        'jobs' => [
            'title' => 'Queues and scheduler',
            'checks' => [
                'queue-robustness' => 'Jobs that are not idempotent or lack tries, backoff, timeout, uniqueness or failed() handling.',
                'scheduler-overlap' => 'Long or critical scheduled tasks without withoutOverlapping() / onOneServer().',
            ],
        ],
        'errors' => [
            'title' => 'Error handling and logging',
            'snapshots' => ['code-candidates'],
            'checks' => [
                'swallowed-exception' => 'Exceptions caught without rethrow or log, catching Throwable broadly.',
                'exception-leak' => 'Exception details returned to users or API clients.',
                'sensitive-logging' => 'Passwords, tokens, full payloads or personal data in logs.',
            ],
        ],
        'injection' => [
            'title' => 'Injection',
            'snapshots' => ['routes', 'code-candidates'],
            'checks' => [
                'sql-injection' => 'Interpolated input in raw SQL, including sort and filter parameters.',
                'command-injection' => 'Shell or process commands built from input.',
                'unsafe-deserialization' => 'unserialize() on untrusted data.',
                'ssrf' => 'HTTP requests to user-controlled URLs or hosts.',
                'path-traversal' => 'File access with user-influenced paths.',
            ],
        ],
        'auth' => [
            'title' => 'Authorization, auth and secrets',
            'snapshots' => ['routes', 'code-candidates'],
            'checks' => [
                'authorization-gap' => 'An action or service with several entry points where only some check permissions.',
                'auth-config' => 'Password rehashing, session invalidation on password change, password rules.',
                'tenant-isolation' => 'Bypassable global scopes, queries without the tenant constraint.',
                'weak-crypto' => 'md5/sha1 for security, homemade crypto, weak tokens, secret comparison without hash_equals().',
                'hardcoded-secret' => 'Secrets in PHP code, config defaults or .env.example; APP_DEBUG defaulting to true.',
            ],
        ],
        'logic' => [
            'title' => 'Logic and transactions',
            'snapshots' => ['routes'],
            'checks' => [
                'logic-bug' => 'A bug found by reading the code (not by a failing test or PHPStan).',
                'transaction-safety' => 'Multi-step writes without a transaction, dispatches inside transactions without afterCommit.',
            ],
        ],
        'performance' => [
            'title' => 'Performance',
            'checks' => [
                'n-plus-one' => 'N+1 queries or queries in loops in PHP classes.',
                'inefficient-query' => 'Collection filtering that belongs in SQL, get()->count(), missing chunk()/lazy().',
                'missing-cache' => 'Repeated expensive work without caching, or caches without invalidation.',
            ],
        ],
        'dead-code' => [
            'title' => 'Dead code',
            'sections' => ['Dead code candidates'],
            'snapshots' => ['routes', 'phpstan'],
            'checks' => [
                'dead-code' => 'Unused controllers, jobs, events, listeners, commands, mailables, policies, services, enum cases or config keys.',
            ],
        ],
        'database' => [
            'title' => 'Database schema and migrations',
            'snapshots' => ['database-schema'],
            'checks' => [
                'missing-index' => 'Missing indexes on foreign keys or frequently filtered or sorted columns.',
                'missing-foreign-key' => 'Reference columns without a foreign key constraint, or with onDelete behaviour that orphans or loses data.',
                'missing-unique' => 'Uniqueness the code relies on (unique validation, firstOrCreate, lookups by a natural key) without a unique index.',
                'column-type' => 'Wrong column types: money as float, dates as strings, unbounded text for short values, nullable where the code never expects null.',
                'migration-safety' => 'Irreversible or risky migrations: missing or empty down(), dropped columns or tables with data, data changes through models.',
            ],
        ],
    ],
];
