<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    |
    | Every run gets its own timestamped folder below this path. The folder
    | contains the preflight results, the four reports and run.md. It gets its
    | own .gitignore, because reports describe concrete weaknesses.
    |
    */

    'output_path' => env('AUDIT_OUTPUT_PATH', storage_path('audit')),

    // null = app.locale. Language for report prose and console output.
    'report_language' => env('AUDIT_LANGUAGE'),

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    |
    | The agent CLI that runs the audit tasks headless. Names follow Laravel
    | Boost: claude_code, opencode, codex. `auto` takes the first agent from
    | boost.json that is installed, then Claude Code, OpenCode, Codex.
    | Models and providers (also self-hosted ones such as Ollama) are set up
    | in the CLI itself; `model` only picks one of them.
    |
    | Agents never get write tools and must not read secret files. Claude Code
    | and OpenCode enforce deny_read. Codex works through a shell and cannot,
    | so it only runs when allow_unrestricted_read is true.
    |
    */

    'agent' => [
        'driver' => env('AUDIT_AGENT', 'auto'),
        'model' => env('AUDIT_MODEL'),                // overrides the driver's model; null = driver/CLI default

        // single: one task at a time. parallel: up to `concurrency` tasks at
        // once. single is the default because most setups cannot serve several
        // agent sessions at the same time: a self-hosted model (Ollama and
        // others) usually answers one request after the other, so parallel
        // tasks queue up there, run into the timeout or fail; subscription
        // CLIs hit their rate limits. Use parallel with a hosted API or a model
        // server that handles concurrent requests. --mode overrides it per run.
        'mode' => env('AUDIT_MODE', 'single'),
        'concurrency' => (int) env('AUDIT_CONCURRENCY', 4), // tasks at once in parallel mode
        'timeout' => 900,                             // seconds per task

        'drivers' => [
            'claude_code' => [
                'binary' => env('AUDIT_CLAUDE_BINARY', 'claude'),
                'model' => null,
                'effort' => env('AUDIT_EFFORT'),              // low, medium, high, xhigh, max
                'max_budget_usd' => env('AUDIT_MAX_BUDGET_USD'), // per task, null = no limit
            ],
            'opencode' => [
                'binary' => env('AUDIT_OPENCODE_BINARY', 'opencode'),
                'model' => null,                              // provider/model, e.g. ollama/qwen3-coder:30b
            ],
            'codex' => [
                'binary' => env('AUDIT_CODEX_BINARY', 'codex'),
                'model' => null,
                'config' => [],                               // extra -c overrides, e.g. 'model_provider="ollama"'
                'allow_unrestricted_read' => false,           // Codex can read .env; set true to accept that
            ],
        ],

        // Read-only Boost MCP tools the agents may use. Tinker, database
        // queries, log readers and get-config (returns secret values) are
        // never allowed.
        'boost_tools' => [
            'application-info',
            'database-connections',
            'database-schema',
            'get-absolute-url',
            'list-artisan-commands',
            'list-available-config-keys',
            'list-routes',
            'search-docs',
        ],

        // Files the agents must never read. Globs are expanded to the files
        // that exist at run time; allow_read punches holes into deny_read.
        'deny_read' => ['.env*', 'auth.json', 'storage/*.key'],
        'allow_read' => ['.env.example'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audits
    |--------------------------------------------------------------------------
    */

    'audits' => [
        'tests' => true,
        'frontend' => true,
        'backend' => true,
        'dependencies' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Preflight
    |--------------------------------------------------------------------------
    |
    | Tools run once, before the agents. Agents only get their results.
    |
    */

    'preflight' => [
        // Tests and browser tests only run when their database is provably
        // not the app database. Set to true only if you know what you do.
        'allow_shared_database' => false,

        'pint' => true,

        'tests' => [
            'command' => null,      // null = php artisan test with JUnit (+ Clover)
            'parallel' => true,     // switched off automatically without paratest
            'coverage' => true,
            'gate' => 'ask',        // ask | abort | continue
            'timeout' => 1800,
        ],

        'browser' => [
            'enabled' => true,              // runtime: --without=browser
            'command' => null,
            'testsuite' => 'Browser',       // excluded from the regular test step
            'path' => 'tests/Browser',      // fallback if the suite does not exist
            'build_assets' => 'if-missing', // if-missing | always | never
            'timeout' => 1200,
            'max_artifacts' => 10,
        ],

        'phpstan' => [
            'enabled' => true,
            'level' => null,        // null = level from phpstan.neon; a number overrides it
            'stub_level' => 5,      // only for a phpstan.neon created by audit:install
            'memory_limit' => '2G',
            'timeout' => 1800,
        ],

        'composer' => [
            'outdated' => true,
            'licenses' => true,
        ],

        'license_allowlist' => [
            'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', 'MPL-2.0',
            'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    // Per task: more findings than this fail validation; agents group
    // instead. Raised automatically when a task has more required findings.
    'max_findings_per_task' => 8,

    'exclude_paths' => ['vendor', 'node_modules', 'storage', 'public/build', 'bootstrap/cache'],

    // Project-specific rules the agents check in addition to their checklist.
    'custom_checks' => [
        'tests' => [],
        'frontend' => [],
        'backend' => [
            // 'Money is always stored as integer cents.',
        ],
        'dependencies' => [],
    ],

    // Experimental, no grade: sum of weight × confidence factor.
    'risk_points' => [
        'weights' => ['critical' => 20, 'high' => 10, 'medium' => 4, 'low' => 1, 'info' => 0],
        'confidence' => ['confirmed' => 1.0, 'likely' => 0.7, 'suspected' => 0.3],
    ],
];
