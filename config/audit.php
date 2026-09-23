<?php

/*
|--------------------------------------------------------------------------
| laravel-audit
|--------------------------------------------------------------------------
|
| Only the settings people usually change. Keep only the keys you change:
| everything missing here falls back to the package default, also after
| an update. Rarely changed settings (preflight tools, report limits,
| Boost tools, license allowlist, risk points) are listed in
| Astro2006\LaravelAudit\Support\AuditConfig::DEFAULTS and can be set here
| with the same key.
|
| Precedence: command option (--agent, --model, --mode) > this file (and
| the AUDIT_* variables in it) > package default.
|
*/

return [

    // Every run gets its own timestamped folder below this path.
    'output_path' => env('AUDIT_OUTPUT_PATH', storage_path('audit')),

    // null = app.locale. Language for report prose and console output.
    'report_language' => env('AUDIT_LANGUAGE'),

    'agent' => [
        // claude_code, opencode, codex or auto (the first agent from
        // boost.json that is installed, then Claude Code, OpenCode, Codex).
        'driver' => env('AUDIT_AGENT', 'auto'),

        // null = the CLI's own default model. With OpenCode: provider/model,
        // e.g. ollama/qwen3-coder:30b.
        'model' => env('AUDIT_MODEL'),

        // Tasks at once. 1 = one after the other: for self-hosted models and
        // subscription CLIs with rate limits. Raise it for a hosted API or a
        // model server that serves concurrent requests.
        'max_concurrency' => (int) env('AUDIT_CONCURRENCY', 1),

        'timeout' => 900,   // seconds per task

        // Tool calls (turns) one agent session may take before it has to
        // answer. Every turn sends the whole conversation again, so long
        // sessions get slow and expensive, above all with local models.
        // null = no limit. Claude Code and OpenCode; Codex has no such limit.
        'max_turns' => (int) env('AUDIT_MAX_TURNS', 15) ?: null,

        // A second call checks each task's findings (keep, downgrade, drop).
        'verify' => true,

        'drivers' => [
            'claude_code' => [
                'binary' => env('AUDIT_CLAUDE_BINARY', 'claude'),
                'effort' => env('AUDIT_EFFORT'),                 // low, medium, high, xhigh, max
                'max_budget_usd' => env('AUDIT_MAX_BUDGET_USD'), // per task, null = no limit
            ],
            'opencode' => [
                'binary' => env('AUDIT_OPENCODE_BINARY', 'opencode'),
            ],
            'codex' => [
                'binary' => env('AUDIT_CODEX_BINARY', 'codex'),
                // Codex works through a shell and cannot keep agents away
                // from .env; it only runs when you accept that here.
                'allow_unrestricted_read' => false,
            ],
        ],

        // Files the agents must never read. Globs are expanded at run time;
        // allow_read punches holes into deny_read.
        'deny_read' => ['.env*', 'auth.json', 'storage/**/*.key'],
        'allow_read' => ['.env.example'],
    ],

    // Which audits run when no --tests, --frontend, … option is given.
    'audits' => [
        'tests' => true,
        'frontend' => true,
        'backend' => true,
        'dependencies' => true,
    ],

    'preflight' => [
        'tests' => [
            'gate' => 'ask',        // failing tests: ask | abort | continue
            'coverage' => true,
            'parallel' => true,     // switched off automatically without paratest
        ],
    ],

    // Project rules the agents check in addition to their checklist.
    'custom_checks' => [
        'tests' => [],
        'frontend' => [],
        'backend' => [
            // 'Money is always stored as integer cents.',
        ],
        'dependencies' => [],
    ],
];
