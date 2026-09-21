<?php

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Audits\TestsAudit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\PromptRenderer;

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

function renderer(?string $override = null): PromptRenderer
{
    return new PromptRenderer(dirname(__DIR__, 2).'/resources/prompts', $override);
}

function backendTask(string $name): AuditTask
{
    return collect((new BackendAudit)->tasks(withCustom: true))->firstWhere('name', $name);
}

it('renders every task prompt without leftover placeholders', function (AuditType $type) {
    $audit = $type->audit();

    foreach ($audit->tasks(withCustom: true) as $task) {
        $prompt = $task->isSummary()
            ? renderer()->renderSummary($audit, $task, promptContext(), '- X-001 · low', [])
            : renderer()->renderTask($audit, $task, promptContext(), ['Frontend map' => 'Blade'], ['backend.other']);

        expect($prompt->text)->not->toMatch('/\{\{[a-z_]+\}\}/', $task->key())
            ->and($prompt->hash)->toHaveLength(8);
    }
})->with(AuditType::cases());

it('fills context, the task checks and the output template', function () {
    $text = renderer()->renderTask(new BackendAudit, backendTask('injection'), promptContext())->text;

    expect($text)
        ->toContain('Task `backend.injection`')
        ->toContain('**Your task: Injection.**')
        ->toContain('- Project: Shop')
        ->toContain('Report language: **German (de)**')
        ->toContain('- `sql-injection` — ')
        ->toContain('- `other` — ')
        ->not->toContain('- `mass-assignment` — ')
        ->toContain('Start directly with the `## Findings` line.')
        ->toContain('### BE-001 — <Title')
        ->toContain('You can read files (read, glob, list).')
        ->toContain('At most 8 findings.')
        ->toContain('Open at most 25 files')
        ->toContain('`missing:<path>`')
        // No real-looking example a weak model could copy as a finding.
        ->not->toContain('OrderController')
        ->not->toContain('CWE-639')
        ->not->toContain('## Context from other tasks');
});

it('renders the verify prompt with the findings to check', function () {
    $prompt = renderer()->renderVerify(new BackendAudit, backendTask('injection'), promptContext(), '### BE-001 — Raw SQL');

    expect($prompt->text)->toContain('Verify `backend.injection`')
        ->toContain('### BE-001 — Raw SQL')
        ->toContain('Blade `{{ }}` already escapes')
        ->toContain('**German (de)**')
        ->not->toMatch('/\{\{[a-z_]+\}\}/')
        ->and($prompt->hash)->toBe(renderer()->verifyHash());
});

it('injects the sections of the tasks it depends on', function () {
    $text = renderer()->renderTask(new BackendAudit, backendTask('laravel'), promptContext(), ['Conventions baseline' => '- Actions use execute()'])->text;
    $missing = renderer()->renderTask(new BackendAudit, backendTask('laravel'), promptContext(), [], ['backend.conventions'])->text;

    expect($text)->toContain('## Context from other tasks')->toContain("### Conventions baseline\n\n- Actions use execute()")
        ->and($missing)->toContain('The task `backend.conventions` did not complete');
});

it('lists the custom checks in the custom task and the owned sections in the template', function () {
    expect(renderer()->renderTask(new BackendAudit, backendTask('custom'), promptContext())->text)->toContain('- Money is stored as integer cents.')
        ->and(renderer()->renderTask(new BackendAudit, backendTask('dead-code'), promptContext())->text)->toContain("## Dead code candidates\n<see your task instructions>");
});

it('renders the summary from the findings list with audit-specific sections', function () {
    $audit = new TestsAudit;
    $task = collect($audit->tasks())->firstWhere('name', 'summary');
    $text = renderer()->renderSummary($audit, $task, promptContext(), '- TST-001 · high · confirmed · `test-failure` — Checkout fails', ['tests.phpstan'])->text;

    expect($text)->toContain('- TST-001 · high · confirmed · `test-failure` — Checkout fails')
        ->toContain('`tests.phpstan`')
        ->toContain('## Top 10 missing tests')
        ->toContain('starting directly with the `## Summary` line');
});

it('keeps the hash stable across runs and changes it with the template', function () {
    $a = renderer()->renderTask(new BackendAudit, backendTask('injection'), promptContext());
    $b = renderer()->renderTask(new BackendAudit, backendTask('injection'), promptContext(['runId' => 'other', 'boostAvailable' => true]), ['X' => 'y']);

    $override = tempDir('prompts');
    mkdir($override.'/tasks/backend', 0777, true);
    file_put_contents($override.'/tasks/backend/injection.md', "# Tuned injection prompt\n");
    $c = renderer($override)->renderTask(new BackendAudit, backendTask('injection'), promptContext());

    expect($a->hash)->toBe($b->hash)
        ->and($c->hash)->not->toBe($a->hash)
        ->and($c->text)->toContain('# Tuned injection prompt')
        ->and(PromptRenderer::auditHash(['b', 'a']))->toBe(PromptRenderer::auditHash(['a', 'b']));
});

it('names published prompts of the single-agent layout', function () {
    $override = tempDir('prompts');
    touch($override.'/backend.md');

    expect(renderer($override)->outdatedOverrides())->toBe(['backend.md'])
        ->and(renderer()->outdatedOverrides())->toBe([]);
});
