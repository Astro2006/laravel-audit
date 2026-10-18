<?php

use Astro2006\LaravelAudit\Standards\GuidelineSource;

function guidelineProject(string $file = 'AGENTS.md'): string
{
    $dir = tempDir('standards');
    file_put_contents($dir.'/'.$file, implode("\n", [
        '<laravel-boost-guidelines>',
        '=== foundation rules ===',
        '',
        '# Laravel Boost Guidelines',
        '- Be concise.',
        '',
        '=== boost rules ===',
        '',
        '- Use `search-docs`.',
        '',
        '=== php rules ===',
        '',
        '# PHP',
        '- Always use curly braces for control structures.',
        '',
        '=== laravel/core rules ===',
        '',
        '- Use `php artisan make:` commands.',
        '',
        '=== astro2006/laravel-audit/core rules ===',
        '',
        '## Laravel Audit',
        '- Activate the skill.',
        '</laravel-boost-guidelines>',
    ]));
    @mkdir($dir.'/.ai/rules', 0777, true);
    file_put_contents($dir.'/.ai/rules/index.md', "| glob | file |\n|---|---|\n| app/Models/** | models.md |\n");
    file_put_contents($dir.'/.ai/rules/models.md', "# Models\n- Models use `\$fillable`, never `\$guarded`.\n");

    return $dir;
}

it('keeps the code rules of Boost and the project and drops the tooling blocks', function () {
    $source = GuidelineSource::for(guidelineProject(), auditConfig());

    expect(array_keys($source->blocks))->toBe(['php', 'laravel/core', 'rules/models.md'])
        ->and($source->render(6000))->toStartWith('## Coding standards')
        ->toContain("### php\n\n# PHP\n- Always use curly braces")
        ->toContain('### rules/models.md')
        ->not->toContain('search-docs')
        ->not->toContain('Activate the skill');
});

it('falls back to CLAUDE.md, cuts long standards and stays silent without Boost', function () {
    $claude = GuidelineSource::for(guidelineProject('CLAUDE.md'), auditConfig());

    expect(array_keys($claude->blocks))->toContain('php')
        ->and($claude->render(80))->toEndWith('(Standards shortened to 80 characters.)')
        ->and(GuidelineSource::for(tempDir('empty'), auditConfig())->render(6000))->toBe('');
});

it('can be switched off or pointed at files', function () {
    $project = guidelineProject();
    file_put_contents($project.'/STANDARDS.md', "- Money is integer cents.\n");

    expect(GuidelineSource::for($project, auditConfig(['agent' => ['standards' => 'none']]))->render(6000))->toBe('')
        ->and(GuidelineSource::for($project, auditConfig(['agent' => ['standards' => ['STANDARDS.md', 'missing.md']]]))->blocks)->toBe(['STANDARDS.md' => "- Money is integer cents.\n"]);
});
