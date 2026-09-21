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
    config()->set('audit.agent.driver', 'claude_code');
    config()->set('audit.agent.drivers.claude_code.binary', $dir.'/vendor/bin/pint'); // any existing file counts as the CLI
    config()->set('audit.report_language', 'en');
    config()->set('audit.summary', 'agent');
    config()->set('audit.agent.verify.enabled', false);

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

/** @return array<string, string> task key => answer */
function dependencyAnswers(): array
{
    $answers = [];

    foreach (['security', 'necessity', 'freshness', 'hygiene', 'summary'] as $task) {
        $answers['dependencies.'.$task] = file_get_contents(fixture('reports/tasks/dependencies-'.$task.'.md'));
    }

    return $answers;
}

/**
 * Fakes the agent CLI (pint stands in for it) and answers per task, told
 * apart by the AUDIT_TASK variable the runner sets.
 *
 * @param  array<string, string>  $answers
 */
function fakeAgent(array $answers, string $pattern = '*pint*-p*', ?Closure $wrap = null): void
{
    $wrap ??= fn (string $answer): string => claudeJson(['result' => $answer]);

    fakeTools([$pattern => fn ($process) => Process::result($wrap($answers[$process->environment['AUDIT_TASK'] ?? ''] ?? "## Findings\nNo findings.\n\n## Limitations\nNone.\n"))]);
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
    fakeAgent(dependencyAnswers());

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('dependencies.security · 3 findings')
        ->expectsOutputToContain('3 findings · 60 turns')
        ->expectsOutputToContain('Findings: Critical 0 · High 0 · Medium 1 · Low 2 · Info 0')
        ->assertExitCode(0);

    $run = latestRun($project);
    $report = file_get_contents($run.'/dependencies.md');
    $manifest = file_get_contents($run.'/run.md');

    expect($report)->toStartWith("---\nformat: 1\naudit: dependencies\n")
        ->toContain('model: claude-opus-5')
        ->toContain('# Dependencies Audit — ')
        ->toContain('### DEP-001 — guzzlehttp/psr7')
        ->toContain("## Summary\n\nOne medium advisory in a transitive package (DEP-001)")
        ->toContain("## Dependency inventory\n\n| Package | prod/dev | Verdict |")
        ->toContain("## Upgrade plan\n\n1. Update guzzlehttp/psr7 (✅ safe patch).")
        ->toContain("## Positive observations\n\n- Lock file committed.")
        ->toContain("### Out of scope observations\n\n- The checkout controller has no rate limit.")
        ->and(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')->model->toBe('claude-opus-5')
        ->and($manifest)->toContain('## Metrics')
        ->toContain('| Advisories (critical/high/medium/low) | 0/0/1/0 |')
        ->toContain('| dependencies | completed | 3 | 0/0/1/2/0 | no | 60 |')
        ->toContain('## Tasks')
        ->toContain('| dependencies.security | completed | 3 | no | 12 |')
        ->toContain('| vulnerable-dependency | medium | confirmed | `package:guzzlehttp/psr7` |')
        ->and($run.'/logs/dependencies.security.prompt.md')->toBeFile()
        ->and(file_get_contents($run.'/logs/dependencies.security.prompt.md'))->toContain('Report language: **English (en)**')
        ->and(file_get_contents($run.'/logs/dependencies.summary.prompt.md'))->toContain('- DEP-003 · low · confirmed · `abandoned-package` — acme/test-only is abandoned (package:acme/test-only)');

    Process::assertRan(fn ($process) => in_array('--restricted', (array) $process->command, true)
        && str_contains(implode(' ', (array) $process->command), 'Read(./.env)'));
});

it('fails the run when a report misses required findings after the repair pass', function () {
    $project = auditProject();
    $answers = dependencyAnswers();
    $answers['dependencies.security'] = preg_replace('/### DEP-003.*?\n---\n/s', '', $answers['dependencies.security']);
    fakeAgent($answers);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('Failed audits: dependencies (partial)')
        ->assertExitCode(3);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md')?->status)->toBe('failed')
        ->and(file_get_contents($run.'/dependencies.md'))->toContain('- The task `dependencies.security` (Advisories and abandoned packages) failed: Report failed validation after the repair pass. These checks were not audited: `vulnerable-dependency`, `abandoned-package`.')
        ->toContain('## Dependency inventory')
        ->and($run.'/logs/dependencies.security.invalid.md')->toBeFile()
        ->and(file_get_contents($run.'/run.md'))->toContain('- dependencies.security: Missing required finding: an `abandoned-package` finding with location `package:acme/test-only`.')
        ->toContain('| dependencies | partial: 1 of 5 tasks failed: security |');
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

it('refuses codex unless the config accepts that it can read secrets', function () {
    $project = auditProject();
    config()->set('audit.agent.drivers.codex.binary', $project.'/vendor/bin/pint');

    $this->artisan('audit', ['--dependencies' => true, '--agent' => 'codex'])
        ->expectsOutputToContain('Codex cannot keep agents away from .env and other secret files (it works through a shell). Set agent.drivers.codex.allow_unrestricted_read to true')
        ->assertExitCode(3);

    config()->set('audit.agent.drivers.codex.allow_unrestricted_read', true);
    fakeAgent(dependencyAnswers(), '*pint*exec*', fn (string $answer): string => '{"type":"thread.started","thread_id":"t1"}'."\n".json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => $answer]]));

    $this->artisan('audit', ['--dependencies' => true, '--agent' => 'codex'])
        ->expectsOutputToContain('Codex can read .env and other secret files')
        ->assertExitCode(0);

    expect(file_get_contents(latestRun($project).'/run.md'))->toContain("\nagent: codex\n");
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'exec --json --skip-git-repo-check'));
});

it('runs one task at a time by default and shows every task as it starts', function () {
    auditProject();
    fakeAgent(dependencyAnswers());

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('Mode: single, one task at a time')
        ->expectsOutputToContain('dependencies · 5 tasks: security, necessity, freshness, hygiene, summary')
        ->expectsOutputToContain('dependencies.security started')
        ->expectsOutputToContain('dependencies.summary started')
        ->assertExitCode(0);
});

it('runs tasks in parallel with --mode=parallel and rejects unknown modes', function () {
    auditProject();
    config()->set('audit.agent.concurrency', 3);
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true, '--mode' => 'parallel'])
        ->expectsOutputToContain('Mode: parallel, 3 tasks at once')
        ->assertExitCode(0);

    $this->artisan('audit', ['--mode' => 'turbo'])
        ->expectsOutputToContain('Unknown agent mode "turbo". Allowed: single, parallel.')
        ->assertExitCode(3);
});

it('rejects unknown agents', function () {
    auditProject();

    $this->artisan('audit', ['--agent' => 'cursor'])
        ->expectsOutputToContain('Unknown agent driver "cursor"')
        ->assertExitCode(3);
});

function hygieneFinding(string $id, string $title, string $constraint): string
{
    return "### {$id} — {$title}\n\n| Field | Value |\n|---|---|\n| Severity | medium |\n| Confidence | confirmed |\n| Category | dependency |\n| Check | loose-constraint |\n| Effort | S |\n| Locations | `composer.json:8` |\n\n**Evidence**\n```json\n{$constraint}\n```\n\n**Impact**\nAny version can be installed.\n\n**Recommendation**\nPin a range.\n\n---\n";
}

it('writes the summary itself and applies the verify pass', function () {
    $project = auditProject();
    config()->set('audit.summary', 'generated');
    config()->set('audit.agent.verify.enabled', true);

    $answers = dependencyAnswers();
    $answers['dependencies.hygiene'] = "## Findings\n\n"
        .hygieneFinding('DEP-001', 'acme/test-only accepts any version', '"acme/test-only": "*",')
        ."\n".hygieneFinding('DEP-002', 'acme/used-lib constraint is too loose', '"acme/used-lib": "^2.0",')
        ."\n".hygieneFinding('DEP-003', 'Made-up constraint', '"acme/fake": "dev-main",')
        ."\n## Limitations\nNone.\n";
    $answers['dependencies.hygiene.verify'] = "## Verdicts\n\n| ID | Verdict | Reason |\n|---|---|---|\n| DEP-001 | keep | Line 8 has `*`. |\n| DEP-002 | drop | `^2.0` is a normal range. |\n";
    fakeAgent($answers);

    $this->artisan('audit', ['--dependencies' => true])->assertExitCode(0);

    $run = latestRun($project);
    $report = file_get_contents($run.'/dependencies.md');
    $manifest = file_get_contents($run.'/run.md');

    expect($report)->toContain('acme/test-only accepts any version')
        ->not->toContain('acme/used-lib constraint is too loose')
        ->not->toContain('Made-up constraint')
        ->toContain('## Summary'."\n\n".'4 findings from 4 tasks (2 medium, 2 low).')
        ->toContain('- 2 findings were dropped')
        ->and($run.'/logs/dependencies.summary.prompt.md')->not->toBeFile()
        ->and($run.'/logs/dependencies.hygiene.verify.prompt.md')->toBeFile()
        ->and(file_get_contents($run.'/logs/dependencies.hygiene.verify.prompt.md'))->toContain('### DEP-001 — acme/test-only accepts any version')
        ->not->toContain('Made-up constraint')
        // Findings that cover an advisory are facts, not claims, and are not verified.
        ->and($run.'/logs/dependencies.security.verify.prompt.md')->not->toBeFile()
        ->and($manifest)->toContain('## Rejected findings')
        ->toContain('| dependencies.hygiene | DEP-002 | acme/used-lib constraint is too loose | verify pass: `^2.0` is a normal range. |')
        ->toContain('| dependencies.hygiene | DEP-003 | Made-up constraint | the code in Evidence does not appear in `composer.json`. |')
        ->toContain('| Wall clock |');
});
