<?php

/*
| Dependencies Audit (DEP): its tasks and the checks each task owns.
|
| Every check belongs to exactly one task. A task is one agent session
| with its own prompt in resources/prompts/agents/dependencies/<task>.md.
| Publish with `php artisan vendor:publish --tag=audit-checks` to change
| checks or leave tasks out for one project.
|
| depends_on: tasks whose sections this task gets as context.
| sections: report sections the task writes.
| snapshots: preflight data in the prompt (the status of all steps is always there).
*/

return [
    // Audit-specific report sections, in report order.
    'sections' => ['Dependency inventory', 'Upgrade plan'],

    'tasks' => [
        'security' => [
            'title' => 'Advisories and abandoned packages',
            'snapshots' => ['composer'],
            'checks' => [
                'vulnerable-dependency' => 'A package with a security advisory (one finding per package).',
                'abandoned-package' => 'An abandoned package.',
            ],
        ],
        'necessity' => [
            'title' => 'Necessity and placement',
            'sections' => ['Dependency inventory'],
            'snapshots' => ['composer'],
            'checks' => [
                'unused-package' => 'A direct dependency without explicit or implicit usage.',
                'misplaced-package' => 'require vs require-dev in the wrong place.',
                'shadow-dependency' => 'Production code using a package that is only installed transitively.',
                'redundant-package' => 'Several packages for the same job, or packages superseded by the framework.',
            ],
        ],
        'freshness' => [
            'title' => 'Freshness and support',
            'sections' => ['Upgrade plan'],
            'snapshots' => ['composer'],
            'checks' => [
                'outdated-major' => 'A direct dependency one or more majors behind.',
                'unsupported-runtime' => 'Laravel or PHP version without bug or security fixes.',
                'stale-package' => 'A package without releases for a long time.',
            ],
        ],
        'hygiene' => [
            'title' => 'Constraints, Composer config and licenses',
            'snapshots' => ['composer'],
            'checks' => [
                'loose-constraint' => 'Constraints like *, dev-*, >= without upper bound, or pins that block patches.',
                'lock-out-of-sync' => 'composer.lock missing or out of sync with composer.json.',
                'composer-config' => 'allow-plugins true, untrusted repositories, scripts executing remote code, platform mismatch.',
                'autoload-bloat' => 'Large files autoloads or classmap bloat.',
                'license-risk' => 'Copyleft licenses in a proprietary project, or unknown licenses.',
            ],
        ],
    ],
];
