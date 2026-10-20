<?php

use Astro2006\LaravelAudit\Audits\Audit;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Audits\TestsAudit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Data\Steps\AboutDTO;
use Astro2006\LaravelAudit\Preflight\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Preflight\Data\Steps\PhpstanDTO;
use Astro2006\LaravelAudit\Preflight\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Prompts\ProjectContextSection;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function promptContext(array $overrides = []): PromptContext
{
    return new PromptContext(...array_merge([
        'projectName' => 'Shop',
        'laravelVersion' => '13.2.0',
        'phpVersion' => '8.4.1',
        'testFramework' => 'pest',
        'frontendStack' => 'livewire, tailwind',
        'phpstanLevel' => '6',
        'browserTests' => 'ran (18 tests)',
        'runId' => '2026-10-02_09-15-40',
        'outputDir' => 'storage/audit/2026-10-02_09-15-40',
        'preflightDir' => 'storage/audit/2026-10-02_09-15-40/preflight',
        'reportLanguage' => 'de',
        'excludePaths' => ['vendor', 'storage'],
        'customChecks' => ['backend' => ['Money is stored as integer cents.']],
        'maxFindings' => 8,
        'boostAvailable' => false,
        'toolsNote' => 'You can read files (read, glob, list).',
    ], $overrides));
}

function backendTask(string $name): AuditTask
{
    return collect((new BackendAudit)->tasks(withCustom: true))->firstWhere('name', $name);
}

it('gives every check to exactly one audit', function () {
    $owners = [];

    foreach (AuditType::cases() as $type) {
        foreach (array_keys($type->audit()->checks()) as $check) {
            if (in_array($check, ['custom', 'other'], true)) {
                continue;
            }

            expect($owners)->not->toHaveKey($check);
            $owners[$check] = $type->value;
        }
    }

    expect(count($owners))->toBeGreaterThan(80);
});

it('splits every audit into tasks that own each check and section exactly once', function (AuditType $type) {
    $audit = $type->audit();
    $tasks = $audit->tasks(withCustom: true);
    $keys = array_map(fn ($task) => $task->key(), $tasks);
    $owned = [];
    $sections = [];

    foreach ($tasks as $task) {
        foreach ($task->checks as $check) {
            expect($owned)->not->toHaveKey($check)
                ->and($audit->checks())->toHaveKey($check);
            $owned[$check] = $task->key();
        }

        foreach ($task->sections as $section) {
            expect($sections)->not->toHaveKey($section);
            $sections[$section] = $task->key();
        }

        foreach ($task->dependencyKeys() as $dependency) {
            expect($keys)->toContain($dependency)
                ->and(array_search($dependency, $keys, true))->toBeLessThan(array_search($task->key(), $keys, true));
        }

        expect(dirname(__DIR__, 2).'/resources/audits/'.$task->promptFile())->toBeFile();
    }

    expect(array_keys($owned))->toEqualCanonicalizing(array_values(array_diff(array_keys($audit->checks()), ['other'])))
        ->and(array_keys($sections))->toEqualCanonicalizing($audit->sections())
        ->and($keys)->toBe(array_unique($keys))
        ->and(end($tasks)->isSummary())->toBeTrue()
        ->and(count($audit->tasks()))->toBe(count($tasks) - 1);
})->with(AuditType::cases());

it('gives every required finding a task that owns its check', function () {
    $summary = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 2)),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanDTO(baselined: 12)),
    ]));
    $owned = array_merge(...array_map(fn ($task) => $task->checks, (new TestsAudit)->tasks()));

    foreach ((new TestsAudit)->requiredFindings($summary) as $required) {
        expect($owned)->toContain($required->check);
    }

    foreach (['vulnerable-dependency', 'abandoned-package', 'lock-out-of-sync'] as $check) {
        expect(array_merge(...array_map(fn ($task) => $task->checks, (new DependenciesAudit)->tasks())))->toContain($check);
    }
});

it('uses unique id prefixes', function () {
    $prefixes = array_map(fn (AuditType $type) => $type->audit()->idPrefix(), AuditType::cases());

    expect($prefixes)->toBe(array_unique($prefixes));
});

it('requires test failure and baseline findings from the tests audit', function () {
    $summary = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 2)),
        StepResult::ok(PreflightStep::Browser, new BrowserDTO(failed: 1, path: 'tests/Browser')),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanDTO(baselined: 12)),
    ]));

    $required = (new TestsAudit)->requiredFindings($summary);

    [, $browser, $baseline] = $required;

    expect($required)->toHaveCount(3)
        ->and($browser->targetPrefix)->toBe('tests/Browser/')
        ->and($baseline->check)->toBe('phpstan-baseline');
});

it('does not require browser findings for environment errors or skipped steps', function () {
    $summary = new PreflightSummary('run', GateDecision::NotRun, new StepResults([
        StepResult::skipped(PreflightStep::Tests, SkipReason::SharedDatabase),
        StepResult::ok(PreflightStep::Browser, new BrowserDTO(failed: 18, environmentError: true)),
    ]));

    expect((new TestsAudit)->requiredFindings($summary))->toBe([]);
});

it('requires one finding per vulnerable and abandoned package', function () {
    $dir = tempDir('preflight');
    mkdir($dir.'/composer');
    Json::writeFile($dir.'/summary.json', ['run_id' => 'run', 'steps' => ['composer' => ['status' => 'ok']]]);
    file_put_contents($dir.'/composer/dependency-facts.json', json_encode([
        'advisories' => [
            ['package' => 'guzzlehttp/psr7', 'advisory' => 'GHSA-1'],
            ['package' => 'guzzlehttp/psr7', 'advisory' => 'GHSA-2'],
        ],
        'abandoned' => [['package' => 'fzaninotto/faker', 'replacement' => 'fakerphp/faker']],
        'validate' => ['lock_in_sync' => false],
    ]));

    $required = (new DependenciesAudit)->requiredFindings(PreflightSummary::fromDirectory($dir));

    expect(array_map(fn ($requirement) => [$requirement->check, $requirement->target], $required))->toBe([
        ['vulnerable-dependency', 'package:guzzlehttp/psr7'],
        ['abandoned-package', 'package:fzaninotto/faker'],
        ['lock-out-of-sync', null],
    ]);
});

it('skips tasks that have nothing to audit', function () {
    $clean = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsDTO(total: 10, passed: 10)),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanDTO(errors: 0)),
    ]));
    $broken = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1)),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanDTO(errors: 3)),
    ]));
    $task = fn (string $name) => collect((new TestsAudit)->tasks())->firstWhere('name', $name);

    expect((new TestsAudit)->skipReason($task('phpstan'), $clean))->toBe('PHPStan reports no errors and no baseline.')
        ->and((new TestsAudit)->skipReason($task('results'), $clean))->toBe('all tests passed and none were skipped.')
        ->and((new TestsAudit)->skipReason($task('coverage'), $clean))->toBeNull()
        ->and((new TestsAudit)->skipReason($task('phpstan'), $broken))->toBeNull()
        ->and((new TestsAudit)->skipReason($task('results'), $broken))->toBeNull();
});

function dependencyPreflight(array $facts): PreflightSummary
{
    $dir = tempDir('preflight');
    mkdir($dir.'/composer');
    Json::writeFile($dir.'/summary.json', ['run_id' => 'run', 'steps' => ['composer' => ['status' => 'ok']]]);
    file_put_contents($dir.'/composer/dependency-facts.json', json_encode($facts));

    return PreflightSummary::fromDirectory($dir);
}

it('skips the security task without advisories and abandoned packages', function () {
    $security = collect((new DependenciesAudit)->tasks())->firstWhere('name', 'security');

    expect((new DependenciesAudit)->skipReason($security, dependencyPreflight(['advisories' => [], 'abandoned' => []])))->toBe('composer audit reports no advisories and no abandoned packages.')
        ->and((new DependenciesAudit)->skipReason($security, dependencyPreflight(['advisories' => [['package' => 'guzzlehttp/psr7']]])))->toBeNull();
});

it('rejects dependency findings the preflight does not back', function () {
    $guard = (new DependenciesAudit)->findingGuard(
        dependencyPreflight(['advisories' => [['package' => 'guzzlehttp/psr7']], 'abandoned' => [['package' => 'acme/unused-lib']]]),
        new ProjectInspector(fixture('project')),
    );
    $finding = fn (string $check, string $location) => new Finding('DEP-001', 't', Severity::High, Confidence::Confirmed, Category::Security, $check, Effort::S, [Location::parse($location)], 1, [], [], 'e', 'i', 'r');

    expect($guard->reject($finding('vulnerable-dependency', 'package:guzzlehttp/psr7')))->toBeNull()
        ->and($guard->reject($finding('vulnerable-dependency', 'package:acme/used-lib')))->toBe('composer audit reports no advisory for `acme/used-lib`.')
        ->and($guard->reject($finding('abandoned-package', 'package:acme/unused-lib')))->toBeNull()
        ->and($guard->reject($finding('abandoned-package', 'package:acme/test-only')))->toBe('Composer reports no abandoned package `acme/test-only`.')
        ->and($guard->reject($finding('outdated-major', 'package:acme/made-up')))->toBe('`package:acme/made-up` is not in composer.lock.')
        ->and($guard->reject($finding('unsupported-runtime', 'package:php')))->toBeNull();
});

it('renders every task prompt without leftover placeholders', function (AuditType $type) {
    $audit = $type->audit();

    foreach ($audit->tasks(withCustom: true) as $task) {
        $prompt = $task->isSummary()
            ? $audit->renderSummary($task, promptContext(), '- X-001 · low', [])
            : $audit->renderTask($task, promptContext(), ['Frontend map' => 'Blade'], ['backend.other']);

        expect($prompt->text)->not->toMatch('/\{\{[a-z_]+\}\}/', $task->key())
            ->and($prompt->hash)->toHaveLength(8);
    }
})->with(AuditType::cases());

it('fills context, the task checks and the output template', function () {
    $text = (new BackendAudit)->renderTask(backendTask('injection'), promptContext())->text;

    expect($text)
        ->toContain('Task `backend.injection`')
        ->toContain('**Your task: Injection.**')
        ->toContain('- Shop · Laravel')
        ->toContain('Write all prose in **German (de)**')
        ->toContain("- `sql-injection`\n")
        ->not->toContain('- `sql-injection` — ')
        ->toContain('- `other` — ')
        ->not->toContain('- `mass-assignment` — ')
        ->toContain('Start your answer directly with the line `## Findings`.')
        ->toContain('### BE-001 — <title')
        ->toContain('You can read files (read, glob, list).')
        ->toContain('At most 8 findings.')
        ->toContain('project files that matter, at most 25.')
        ->toContain('`missing:<path>`')
        // No real-looking example a weak model could copy as a finding.
        ->not->toContain('OrderController')
        ->not->toContain('CWE-639')
        ->not->toContain('## Context from other tasks');
});

it('renders the verify prompt with the findings to check', function () {
    $prompt = (new BackendAudit)->renderVerify(backendTask('injection'), promptContext(), '### BE-001 — Raw SQL');

    expect($prompt->text)->toContain('Verify `backend.injection`')
        ->toContain('### BE-001 — Raw SQL')
        ->toContain('Blade `{{ }}` already escapes')
        ->toContain('**German (de)**')
        ->not->toMatch('/\{\{[a-z_]+\}\}/')
        ->and($prompt->hash)->toBe((new BackendAudit)->verifyHash());
});

it('injects the sections of the tasks it depends on', function () {
    $text = (new BackendAudit)->renderTask(backendTask('laravel'), promptContext(), ['Conventions baseline' => '- Actions use execute()'])->text;
    $missing = (new BackendAudit)->renderTask(backendTask('laravel'), promptContext(), [], ['backend.conventions'])->text;

    expect($text)->toContain('## Context from other tasks')->toContain("### Conventions baseline\n\n- Actions use execute()")
        ->and($missing)->toContain('The task `backend.conventions` did not complete');
});

it('lists the custom checks in the custom task and the owned sections in the template', function () {
    expect((new BackendAudit)->renderTask(backendTask('custom'), promptContext())->text)->toContain('- Money is stored as integer cents.')
        ->and((new BackendAudit)->renderTask(backendTask('dead-code'), promptContext())->text)->toContain("## Dead code candidates\n<see your task instructions>");
});

it('renders the summary from the findings list with audit-specific sections', function () {
    $agent = new TestsAudit;
    $task = collect($agent->tasks())->firstWhere('name', 'summary');
    $text = $agent->renderSummary($task, promptContext(), '- TST-001 · high · confirmed · `test-failure` — Checkout fails', ['tests.phpstan'])->text;

    expect($text)->toContain('- TST-001 · high · confirmed · `test-failure` — Checkout fails')
        ->toContain('`tests.phpstan`')
        ->toContain('## Top 10 missing tests')
        ->toContain('starting directly with the `## Summary` line');
});

it('keeps the hash stable across runs and changes it with the template', function () {
    $a = (new BackendAudit)->renderTask(backendTask('injection'), promptContext());
    $b = (new BackendAudit)->renderTask(backendTask('injection'), promptContext(['runId' => 'other', 'boostAvailable' => true]), ['X' => 'y']);

    $override = tempDir('prompts');
    mkdir($override.'/backend/tasks', 0777, true);
    file_put_contents($override.'/backend/tasks/injection.md', "# Tuned injection prompt\n");
    $c = (new BackendAudit($override))->renderTask(backendTask('injection'), promptContext());

    expect($a->hash)->toBe($b->hash)
        ->and($c->hash)->not->toBe($a->hash)
        ->and($c->text)->toContain('# Tuned injection prompt')
        ->and(Audit::auditHash(['b', 'a']))->toBe(Audit::auditHash(['a', 'b']));
});

it('names published prompts and checks of an older layout', function () {
    $resources = tempDir('resources');
    mkdir($resources.'/prompts/vendor/laravel-audit/agents', 0777, true);
    mkdir($resources.'/audits/vendor/laravel-audit/backend', 0777, true);
    touch($resources.'/prompts/vendor/laravel-audit/agents/_task.md');
    touch($resources.'/audits/vendor/laravel-audit/backend.php');
    touch($resources.'/audits/vendor/laravel-audit/backend/audit.php');

    expect(PromptTemplates::outdated($resources))->toBe(['audits/vendor/laravel-audit/backend.php', 'prompts/vendor/laravel-audit/agents/_task.md'])
        ->and(PromptTemplates::outdated(tempDir('resources')))->toBe([]);
});

it('puts the preflight status and the task\'s own snapshots into the prompt', function () {
    $context = promptContext(['snapshots' => ['status' => "### Preflight steps\n| S-01 | tests | ok | |", 'routes' => "### Routes — 3 routes · 1 flagged candidates\n| R-01 | no throttle | POST /login | LoginController |"]]);

    $injection = (new BackendAudit)->renderTask(backendTask('injection'), $context)->text;
    $jobs = (new BackendAudit)->renderTask(backendTask('jobs'), $context)->text;
    $dead = (new BackendAudit)->renderTask(backendTask('dead-code'), $context)->text;

    expect($injection)->toContain("## Preflight snapshot\n")
        ->toContain('| S-01 | tests | ok | |')
        ->toContain('| R-01 | no throttle | POST /login | LoginController |')
        ->and($jobs)->toContain('| S-01 | tests | ok | |')->not->toContain('R-01')
        ->and($dead)->toContain("### PHPStan\nNo data in this run (see the preflight steps).");
});

it('includes the snapshot types in the task hash', function () {
    $routes = backendTask('injection');
    $none = new AuditTask($routes->audit, $routes->name, $routes->title, $routes->checks, $routes->sections, $routes->dependsOn);

    expect((new BackendAudit)->taskHash($routes))->not->toBe((new BackendAudit)->taskHash($none));
});

it('never points task prompts at the raw preflight JSON files', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/audits', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $text = file_get_contents((string) $file);

        expect($text)->not->toMatch('/\{\{preflight_dir\}\}\/[\w\/-]+\.json/', (string) $file)
            ->not->toContain('summary.json');
    }
});

it('shows the task its own finding limit', function () {
    expect((new BackendAudit)->renderTask(backendTask('injection'), promptContext(), maxFindings: 11)->text)->toContain('At most 11 findings.')
        ->and((new BackendAudit)->renderTask(backendTask('injection'), promptContext())->text)->toContain('At most 8 findings.');
});

it('keeps every task prompt within its size budget', function (AuditType $type) {
    $audit = $type->audit();

    foreach ($audit->tasks(withCustom: true) as $task) {
        if ($task->isSummary()) {
            continue;
        }

        expect(strlen($audit->renderTask($task, promptContext())->text))->toBeLessThanOrEqual(7_100, $task->key());
    }
})->with(AuditType::cases());

it('gives the coding standards to the standards-stage tasks and the conventions baseline only', function () {
    $context = promptContext(['standards' => "## Coding standards\n\n### php\n- Always use curly braces."]);
    $audit = new BackendAudit;

    expect($audit->renderTask(backendTask('laravel'), $context)->text)->toContain("### php\n- Always use curly braces.")
        ->and($audit->renderTask(backendTask('conventions'), $context)->text)->toContain('## Coding standards')
        ->and($audit->renderTask(backendTask('injection'), $context)->text)->not->toContain('## Coding standards')
        ->and($audit->renderTask(backendTask('laravel'), $context)->hash)->not->toBe($audit->renderTask(backendTask('laravel'), promptContext())->hash);
});

it('tells the tasks about the project context and the runtime facts', function () {
    $section = ProjectContextSection::render('Single-user app behind a Cloudflare tunnel.', new AboutDTO('Shop', '13.2.0', '8.4.1', 'local', false, ['database' => 'pgsql', 'queue' => 'database', 'mail' => 'smtp']));
    $context = promptContext(['projectContext' => $section]);

    expect($section)->toStartWith("## Project context\n\nSingle-user app behind a Cloudflare tunnel.")
        ->toContain('Runtime facts: Laravel 13.2.0 · PHP 8.4.1 · database pgsql · queue database · mail smtp.')
        ->toContain('Only when it says the app has a single user is a finding that needs another logged-in user at most `low`')
        ->not->toContain('The project context is not set')
        ->and(ProjectContextSection::render(null, new AboutDTO('Shop', '13.2.0', '8.4.1')))->toContain('Runtime facts: Laravel 13.2.0 · PHP 8.4.1.')
        ->toContain('The project context is not set: do not assume how many users the app has')
        ->not->toContain('single user')
        ->and(ProjectContextSection::render(null, null))->toBe('')
        ->and((new BackendAudit)->renderTask(backendTask('auth'), $context)->text)->toContain('## Project context')
        ->and((new BackendAudit)->renderVerify(backendTask('auth'), $context, 'x')->text)->toContain('Runtime facts: Laravel 13.2.0');
});
