<?php

use Astro2006\LaravelAudit\Audits\BackendAudit;
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
        'maxFindings' => 25,
        'boostAvailable' => false,
    ], $overrides));
}

it('renders every audit prompt without leftover placeholders', function (AuditType $type) {
    $prompt = (new PromptRenderer(dirname(__DIR__, 2).'/resources/prompts'))->render($type->audit(), promptContext());

    expect($prompt->text)->not->toMatch('/\{\{[a-z_]+\}\}/')
        ->and($prompt->hash)->toHaveLength(8);
})->with(AuditType::cases());

it('fills context, checks and custom checks', function () {
    $text = (new PromptRenderer(dirname(__DIR__, 2).'/resources/prompts'))->render(new BackendAudit, promptContext())->text;

    expect($text)
        ->toContain('- Project: Shop')
        ->toContain('Report language: **German (de)**')
        ->toContain('- `mass-assignment` — ')
        ->toContain('- Money is stored as integer cents. (use check `custom`)')
        ->toContain('### BE-001 — <Title')
        ->toContain('Laravel Boost is not available in this run.')
        ->toContain('At most 25 findings.');
});

it('keeps the hash stable across runs and changes it with the template', function () {
    $renderer = new PromptRenderer(dirname(__DIR__, 2).'/resources/prompts');

    $a = $renderer->render(new BackendAudit, promptContext());
    $b = $renderer->render(new BackendAudit, promptContext(['runId' => 'other', 'boostAvailable' => true]));

    $override = tempDir('prompts');
    file_put_contents($override.'/backend.md', "# Tuned backend prompt\n{{custom_checks}}");
    $c = (new PromptRenderer(dirname(__DIR__, 2).'/resources/prompts', $override))->render(new BackendAudit, promptContext());

    expect($a->hash)->toBe($b->hash)
        ->and($c->hash)->not->toBe($a->hash)
        ->and($c->text)->toContain('# Tuned backend prompt');
});
