<?php

/*
|--------------------------------------------------------------------------
| laravel-audit
|--------------------------------------------------------------------------
|
| Keep only the keys you change. Everything missing here falls back to the
| package default, also after an update; every key of
| Astro2006\LaravelAudit\Support\AuditConfig::DEFAULTS can be set here.
|
| Precedence: command option > this file and its AUDIT_* variables > default.
|
|   1. Your project     what the app is, which audits run, your own rules
|   2. The agent        which CLI and model, how fast, what it may spend
|   3. Guard rails      files the agents never read, the failing-tests gate
|   4. Rarely changed   output folder, language, verify, binaries
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | 1. Your project
    |--------------------------------------------------------------------------
    */

    'project' => [
        // One or two sentences the agents read before they rate a finding:
        // who uses the app, how it is deployed, what is reachable from outside.
        // Without it the agents must not guess the user model, and every
        // authorization finding is rated as if any logged-in user may be hostile.
        // e.g. 'Family app with a few accounts and roles, public on Laravel Cloud.'
        'context' => env('AUDIT_PROJECT_CONTEXT'),
    ],

    // The audits a plain `php artisan audit` runs. --tests, --frontend, …
    // pick single ones regardless of this list.
    'audits' => [
        'tests' => true,
        'frontend' => true,
        'backend' => true,
        'dependencies' => true,
    ],

    // Rules of this project the agents check on top of their own checklist.
    'custom_checks' => [
        'tests' => [],
        'frontend' => [],
        'backend' => [
            // 'Money is always stored as integer cents.',
        ],
        'dependencies' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | 2. The agent
    |--------------------------------------------------------------------------
    */

    'agent' => [
        // claude_code, opencode or auto (the first installed agent from
        // boost.json, then Claude Code, then OpenCode).
        'driver' => env('AUDIT_AGENT', 'auto'),

        // null = the CLI's own default. With OpenCode: provider/model,
        // e.g. ollama/qwen3-coder:30b.
        'model' => env('AUDIT_MODEL'),

        // Tasks at once. 1 = one after the other, right for subscription CLIs
        // with rate limits and for local models. Raise it for a hosted API.
        'max_concurrency' => (int) env('AUDIT_CONCURRENCY', 1),

        // Tool calls one task may make before it has to answer. Each call
        // resends the conversation, so long sessions get slow and expensive.
        // null = no limit.
        'max_turns' => (int) env('AUDIT_MAX_TURNS', 15) ?: null,

        // Seconds one task may run.
        'timeout' => 900,

        // Pause the run before a task that would push its total above this
        // amount; continue later with --resume. null = no limit. Only CLIs
        // that report costs (Claude Code with an API key) can enforce it.
        'run_budget_usd' => env('AUDIT_RUN_BUDGET_USD'),

        'drivers' => [
            'claude_code' => [
                'effort' => env('AUDIT_EFFORT', 'medium'),       // low, medium, high, xhigh, max
                'max_budget_usd' => env('AUDIT_MAX_BUDGET_USD'), // per task, null = no limit
                'binary' => env('AUDIT_CLAUDE_BINARY', 'claude'),
            ],
            'opencode' => [
                'binary' => env('AUDIT_OPENCODE_BINARY', 'opencode'),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | 3. Guard rails
        |----------------------------------------------------------------------
        */

        // Files the agents must never read. Globs; allow_read punches holes.
        'deny_read' => ['.env*', 'auth.json', 'storage/**/*.key'],
        'allow_read' => ['.env.example'],

        /*
        |----------------------------------------------------------------------
        | 4. Rarely changed
        |----------------------------------------------------------------------
        */

        // A second, short call per task checks its findings (keep, downgrade,
        // drop). false switches it off; the severity it starts at and more
        // are agent.verify.from and agent.verify.threshold in DEFAULTS.
        'verify' => true,

        // php artisan audit:orchestrate: the model of the orchestrator's own
        // session (null = agent.model). Its caps are in DEFAULTS.
        'orchestrator' => [
            'model' => env('AUDIT_ORCHESTRATOR_MODEL'),
        ],
    ],

    'preflight' => [
        'tests' => [
            // What happens when the test suite is red: ask | abort | continue.
            'gate' => 'ask',
            'coverage' => true,
            'parallel' => true, // switched off automatically without paratest
        ],
    ],

    // Every run gets its own timestamped folder below this path.
    'output_path' => env('AUDIT_OUTPUT_PATH', storage_path('audit')),

    // Language of the reports and the console. null = app.locale.
    'report_language' => env('AUDIT_LANGUAGE'),
];
