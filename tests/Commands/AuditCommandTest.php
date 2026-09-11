<?php

use Astro2006\LaravelAudit\Data\Run\RunFrontmatter;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Illuminate\Support\Facades\Process;

function auditProject(): string
{
    $dir = tempDir('project');
    (new PreflightReuse)->copy(fixture('project'), $dir);

    config()->set('audit.base_path', $dir);
    config()->set('audit.output_path', $dir.'/storage/audit');
    config()->set('audit.agent.binary', $dir.'/vendor/bin/pint'); // any existing file counts as the CLI
    config()->set('audit.report_language', 'en');

    return $dir;
}

function fakeTools(array $extra = []): void
{
    Process::fake([
        ...$extra,
        '*about*' => Process::result(json_encode(['environment' => ['application_name' => 'Shop', 'laravel_version' => '13.2.0', 'php_version' => '8.4.1', 'environment' => 'local']])),
        '*composer*audit*' => Process::result(file_get_contents(fixture('tools/composer-audit.json')), exitCode: 1),
        '*composer*outdated*' => Process::result(file_get_contents(fixture('tools/composer-outdated.json'))),
        '*composer*licenses*' => Process::result(file_get_contents(fixture('tools/composer-licenses.json'))),
        '*composer*show*' => Process::result('{"installed": []}'),
        '*composer*validate*' => Process::result('./composer.json is valid'),
        '*composer*why*' => Process::result('laravel/framework v13.2.0 requires guzzlehttp/psr7 (^2.0)'),
        '*git*' => Process::result('', exitCode: 1),
    ]);
}

function latestRun(string $project): string
{
    $runs = glob($project.'/storage/audit/*', GLOB_ONLYDIR);
    sort($runs);

    return end($runs);
}

it('runs the preflight only', function () {
    $project = auditProject();
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true])
        ->expectsOutputToContain('Composer')
        ->expectsOutputToContain('Preflight done, no agents started.')
        ->assertExitCode(0);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('preflight-only')->audits->toBe('dependencies')->preflightComplete->toBeTrue()
        ->and($run.'/preflight/summary.json')->toBeFile()
        ->and($run.'/preflight/composer/dependency-facts.json')->toBeFile()
        ->and($project.'/storage/audit/.gitignore')->toBeFile();

    Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '-p --output-format'));
});

it('runs an audit end to end with a validated report', function () {
    $project = auditProject();
    fakeTools(['*pint*-p*' => Process::result(claudeJson(['result' => file_get_contents(fixture('reports/dependencies-valid.md'))]))]);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('3 findings')
        ->expectsOutputToContain('Findings: Critical 0 · High 0 · Medium 1 · Low 2 · Info 0')
        ->assertExitCode(0);

    $run = latestRun($project);
    $report = file_get_contents($run.'/dependencies.md');
    $manifest = file_get_contents($run.'/run.md');

    expect($report)->toStartWith("---\nformat: 1\naudit: dependencies\n")
        ->toContain('model: claude-opus-5')
        ->toContain('# Dependencies Audit — ')
        ->toContain('### DEP-001 — guzzlehttp/psr7')
        ->and(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')->model->toBe('claude-opus-5')
        ->and($manifest)->toContain('## Metrics')
        ->toContain('| Advisories (critical/high/medium/low) | 0/0/1/0 |')
        ->toContain('| dependencies | completed | 3 | 0/0/1/2/0 | no | 12 |')
        ->toContain('| vulnerable-dependency | medium | confirmed | `package:guzzlehttp/psr7` |')
        ->and($run.'/logs/dependencies.prompt.md')->toBeFile()
        ->and(file_get_contents($run.'/logs/dependencies.prompt.md'))->toContain('Report language: **English (en)**');

    Process::assertRan(fn ($process) => in_array('--restricted', (array) $process->command, true)
        && str_contains(implode(' ', (array) $process->command), 'Read(./.env)'));
});

it('fails the run when a report misses required findings after the repair pass', function () {
    $project = auditProject();
    $partial = preg_replace('/### DEP-003.*?\n---\n/s', '', file_get_contents(fixture('reports/dependencies-valid.md')));
    fakeTools(['*pint*-p*' => Process::result(claudeJson(['result' => $partial]))]);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('Failed audits: dependencies')
        ->assertExitCode(3);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md')?->status)->toBe('failed')
        ->and($run.'/dependencies.md')->not->toBeFile()
        ->and($run.'/logs/dependencies.invalid.md')->toBeFile()
        ->and(file_get_contents($run.'/run.md'))->toContain('Missing required finding: an `abandoned-package` finding with location `package:acme/test-only`.');
});

it('reuses an earlier preflight', function () {
    $project = auditProject();
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true])->assertExitCode(0);
    $first = basename(latestRun($project));
    sleep(1);

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true, '--reuse-preflight' => null])
        ->expectsOutputToContain('Reusing the preflight of run '.$first)
        ->assertExitCode(0);

    expect(RunFrontmatter::read(latestRun($project).'/run.md')?->preflightFrom)->toBe($first);
    Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'audit --format=json'), 1);
});

it('rejects invalid --without values', function () {
    auditProject();

    $this->artisan('audit', ['--without' => ['dusk']])
        ->expectsOutputToContain('Unknown --without value "dusk"')
        ->assertExitCode(3);
});

it('never runs in production', function () {
    auditProject();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('never runs in production')
        ->assertExitCode(3);
});

it('speaks german when the report language is german', function () {
    auditProject();
    config()->set('audit.report_language', 'de');
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true])
        ->expectsOutputToContain('Preflight fertig, keine Agents gestartet.')
        ->assertExitCode(0);
});
