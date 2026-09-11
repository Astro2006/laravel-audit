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
    | MVP1 ships one driver: Claude Code, started headless. Agents run without
    | write tools, without a shell and without access to .env files.
    |
    */

    'agent' => [
        'driver' => 'claude',
        'binary' => env('AUDIT_CLAUDE_BINARY', 'claude'),
        'model' => env('AUDIT_MODEL'),                // null = CLI default
        'effort' => env('AUDIT_EFFORT'),              // null = CLI default (low, medium, high, xhigh, max)
        'max_budget_usd' => env('AUDIT_MAX_BUDGET_USD'), // per agent, null = no limit
        'concurrency' => 4,                           // lower to 2 if you hit rate limits
        'timeout' => 1800,                            // seconds per agent

        // Read-only Boost MCP tools the agents may use. Tinker, database
        // queries and log readers are never allowed.
        'boost_tools' => [
            'application-info',
            'database-connections',
            'database-schema',
            'get-absolute-url',
            'get-config',
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

    // More findings than this fail validation; agents group instead.
    'max_findings' => 25,

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
