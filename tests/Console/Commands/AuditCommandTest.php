<?php

use Astro2006\LaravelAudit\Run\Data\RunFrontmatter;
use Astro2006\LaravelAudit\Run\Phases\PauseRun;
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
        '*artisan*test*' => fn ($process) => greenTestRun($process->command),
        '*git*' => Process::result('', exitCode: 1),
    ]);
}

/** Writes a passing JUnit report where the test step asked for it. */
function greenTestRun(array|string $command): mixed
{
    preg_match('/--log-junit=(\S+)/', implode(' ', (array) $command), $match);
    file_put_contents($match[1], '<?xml version="1.0"?><testsuites><testsuite name="Feature" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.1"><testcase name="it works" file="/app/tests/Feature/HomeTest.php" class="Tests\Feature\HomeTest" assertions="1" time="0.1"/></testsuite></testsuites>');

    return Process::result('');
}

/** Writes a JUnit report with one failing test where the test step asked for it. */
function redTestRun(array|string $command): mixed
{
    preg_match('/--log-junit=(\S+)/', implode(' ', (array) $command), $match);
    file_put_contents($match[1], '<?xml version="1.0"?><testsuites><testsuite name="Feature" tests="2" assertions="2" errors="0" failures="1" skipped="0" time="0.1"><testcase name="it works" file="/app/tests/Feature/HomeTest.php" class="Tests\Feature\HomeTest" assertions="1" time="0.1"/><testcase name="it checks out" file="/app/tests/Feature/CheckoutTest.php" class="Tests\Feature\CheckoutTest" assertions="1" time="0.1"><failure type="Failure">Expected 200, got 500.</failure></testcase></testsuite></testsuites>');

    return Process::result('', exitCode: 1);
}

/** @return array<string, string> task key => answer */
function dependencyAnswers(): array
{
    $answers = [];

    foreach (['security', 'necessity', 'freshness', 'hygiene', 'summary'] as $task) {
        $answers['dependencies.'.$task] = file_get_contents(fixture('reports/tasks/dependencies-'.$task.'.md'));
    }

    $answers['results'] = "## Summary\nOne medium advisory (DEP-001) is the only issue worth fixing soon; the rest is housekeeping.\n";

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

it('warns about config keys that are no longer read', function () {
    auditProject();
    fakeTools();
    config()->set('audit.agent.effort', 'high');

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true])
        ->expectsOutputToContain('config/audit.php sets agent.effort, which is no longer read. Use agent.drivers.claude_code.effort instead.')
        ->assertExitCode(0);
});

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
        ->and(file_get_contents($run.'/logs/dependencies.security.prompt.md'))->toContain('Write all prose in **English (en)**')
        ->and(file_get_contents($run.'/logs/dependencies.summary.prompt.md'))->toContain('- DEP-003 · low · confirmed · `abandoned-package` — acme/test-only is abandoned (package:acme/test-only)');

    Process::assertRan(fn ($process) => in_array('--restricted', (array) $process->command, true)
        && str_contains(implode(' ', (array) $process->command), 'Read(./.env)'));
});

it('writes the run overview once every selected audit is done', function () {
    $project = auditProject();
    fakeAgent(dependencyAnswers());

    $this->artisan('audit', ['--dependencies' => true])->assertExitCode(0);

    $run = latestRun($project);

    expect($run.'/logs/results.prompt.md')->toBeFile()
        ->and(file_get_contents($run.'/logs/results.prompt.md'))->toContain('### Dependencies Audit (DEP)')
        ->and(file_get_contents($run.'/run.md'))->toContain("## Overview\n\nOne medium advisory (DEP-001) is the only issue worth fixing soon; the rest is housekeeping.");
});

it('gives each task a snapshot of the preflight instead of the raw files', function () {
    $project = auditProject();
    fakeAgent(dependencyAnswers());

    $this->artisan('audit', ['--dependencies' => true])->assertExitCode(0);

    $run = latestRun($project);
    $prompt = file_get_contents($run.'/logs/dependencies.security.prompt.md');

    expect($run.'/preflight/snapshots/composer.md')->toBeFile()
        ->and($run.'/preflight/snapshots/status.md')->toBeFile()
        ->and(glob($run.'/preflight/details/composer/D-*.md'))->not->toBeEmpty()
        ->and($prompt)->toContain('## Preflight snapshot')
        ->toContain('### Composer — ')
        ->toContain('| D-01 | advisories | guzzlehttp/psr7 |')
        ->toContain('/preflight/details/composer/<ID>.md`')
        ->not->toContain('dependency-facts.json')
        ->not->toContain('summary.json');

    // A reused preflight gets fresh snapshots with the new run's paths.
    $this->artisan('audit', ['--dependencies' => true, '--reuse-preflight' => basename($run)])->assertExitCode(0);
    $reused = latestRun($project);

    expect($reused)->not->toBe($run)
        ->and(file_get_contents($reused.'/preflight/snapshots/composer.md'))->toContain(basename($reused).'/preflight/details/composer/');
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

it('marks the run failed when there is no preflight to reuse', function () {
    $project = auditProject();
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--reuse-preflight' => null])
        ->expectsOutputToContain('No run with a complete preflight found')
        ->assertExitCode(3);

    expect(RunFrontmatter::read(latestRun($project).'/run.md'))->status->toBe('failed');
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '-p --output-format'));
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

it('runs one task at a time by default and shows every task as it starts', function () {
    auditProject();
    fakeAgent(dependencyAnswers());

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('Mode: one task at a time')
        ->expectsOutputToContain('dependencies · 5 tasks: security, necessity, freshness, hygiene, summary')
        ->expectsOutputToContain('dependencies.security started')
        ->expectsOutputToContain('dependencies.summary started')
        ->assertExitCode(0);
});

it('runs several tasks at once with --concurrency or agent.max_concurrency and rejects nonsense', function () {
    auditProject();
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true, '--concurrency' => '3'])
        ->expectsOutputToContain('Mode: up to 3 tasks at once')
        ->assertExitCode(0);

    config()->set('audit.agent.max_concurrency', 4);

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true])
        ->expectsOutputToContain('Mode: up to 4 tasks at once')
        ->assertExitCode(0);

    $this->artisan('audit', ['--concurrency' => '0'])
        ->expectsOutputToContain('--concurrency must be a whole number of at least 1, "0" given.')
        ->assertExitCode(3);
});

it('warns about the mode keys that max_concurrency replaced', function () {
    auditProject();
    fakeTools();
    config()->set('audit.agent.mode', 'parallel');

    $this->artisan('audit', ['--dependencies' => true, '--preflight-only' => true])
        ->expectsOutputToContain('config/audit.php sets agent.mode, which is no longer read. Use agent.max_concurrency instead.')
        ->assertExitCode(0);
});

it('rejects unknown agents', function () {
    auditProject();

    $this->artisan('audit', ['--agent' => 'cursor'])
        ->expectsOutputToContain('Unknown agent driver "cursor"')
        ->assertExitCode(3);
});

/** Medium and suspected, so the verify pass checks it. */
function hygieneFinding(string $id, string $title, string $constraint): string
{
    return "### {$id} — {$title}\n\n| Field | Value |\n|---|---|\n| Severity | medium |\n| Confidence | suspected |\n| Category | dependency |\n| Check | loose-constraint |\n| Effort | S |\n| Locations | `composer.json:8` |\n\n**Evidence**\n```json\n{$constraint}\n```\n\n**Impact**\nAny version can be installed.\n\n**Recommendation**\nPin a range.\n\n---\n";
}

it('picks skills for the tasks once and gives them to the tasks', function () {
    $project = auditProject();
    @mkdir($project.'/.claude/skills/composer-rules', 0777, true);
    file_put_contents($project.'/.claude/skills/composer-rules/SKILL.md', "---\nname: composer-rules\ndescription: How this team pins Composer packages.\n---\nPin every package to a caret constraint.\n");
    fakeAgent([...dependencyAnswers(), 'skills' => '{"dependencies.hygiene": ["composer-rules"]}']);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('Picking skills for the tasks from 1 installed (one agent call) …')
        ->expectsOutputToContain('Skills: 1 installed · assigned to 1 tasks (agent)')
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'skills' && str_contains(implode(' ', (array) $process->command), '--tools  --max-turns 1'));

    $run = latestRun($project);

    expect(file_get_contents($run.'/logs/dependencies.hygiene.prompt.md'))->toContain('### Skill `composer-rules`')
        ->and(file_get_contents($run.'/logs/dependencies.security.prompt.md'))->not->toContain('## Skills')
        ->and(json_decode(file_get_contents($run.'/skills.json'), true)['tasks'])->toBe(['dependencies.hygiene' => ['composer-rules']]);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('assigned to 1 tasks (cache)')
        ->assertExitCode(0);
});

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

    Process::assertRan(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.hygiene.verify' && str_contains(implode(' ', (array) $process->command), '--max-turns 6'));
    Process::assertRan(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.hygiene' && str_contains(implode(' ', (array) $process->command), '--max-turns 15'));

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

it('asks at the test gate once the preflight is done and stops when told to', function () {
    $project = auditProject();
    config()->set('audit.audits.dependencies.preflight', ['about', 'stack', 'composer', 'tests']);
    fakeTools(['*--log-junit*' => fn ($process) => redTestRun($process->command)]);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('1 tests fail. First failures:')
        ->expectsConfirmation('Continue with the audits anyway?', 'no')
        ->expectsOutputToContain('Aborted at the test gate.')
        ->assertExitCode(2);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('aborted')->preflightComplete->toBeTrue()
        ->and(file_get_contents($run.'/run.md'))->toContain('gate: aborted');
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '-p --output-format'));
});

it('starts the agents without asking with --force', function () {
    $project = auditProject();
    config()->set('audit.audits.dependencies.preflight', ['about', 'stack', 'composer', 'tests']);
    fakeTools([
        '*pint*-p*' => fn ($process) => Process::result(claudeJson(['result' => dependencyAnswers()[$process->environment['AUDIT_TASK'] ?? ''] ?? "## Findings\nNo findings.\n\n## Limitations\nNone.\n"])),
        '*--log-junit*' => fn ($process) => redTestRun($process->command),
    ]);

    $this->artisan('audit', ['--dependencies' => true, '--force' => true])
        ->expectsOutputToContain('1 tests fail. First failures:')
        ->doesntExpectOutputToContain('Continue with the audits anyway?')
        ->assertExitCode(0);

    expect(file_get_contents(latestRun($project).'/run.md'))->toContain('gate: forced');
});

it('resumes a run and runs only the tasks without a completed result', function () {
    $project = auditProject();
    $answers = dependencyAnswers();
    fakeTools(['*pint*-p*' => fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.security'
        ? Process::result(claudeJson(['result' => $answers['dependencies.security']]))
        : Process::result(claudeJson(['subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'API Error: 500']), exitCode: 1)]);

    $this->artisan('audit', ['--dependencies' => true])->assertExitCode(3);
    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('failed')
        ->and($run.'/tool/tasks/dependencies.security.json')->toBeFile()
        ->and($run.'/tool/tasks/dependencies.hygiene.json')->toBeFile();

    fakeAgent($answers);

    $this->artisan('audit', ['--resume' => null])
        ->expectsOutputToContain('Resuming run '.basename($run).': 1 tasks keep their result, the others run again.')
        ->assertExitCode(0);

    expect(latestRun($project))->toBe($run)
        ->and(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')
        ->and(file_get_contents($run.'/dependencies.md'))->toContain('### DEP-001 — guzzlehttp/psr7')
        ->toContain("## Upgrade plan\n\n1. Update guzzlehttp/psr7 (✅ safe patch).")
        ->and(file_get_contents($run.'/run.md'))->toContain("## Overview\n\nOne medium advisory (DEP-001)");

    Process::assertRanTimes(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.security', 1);
    Process::assertRanTimes(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.necessity', 2);
});

it('says so when there is no run to resume', function () {
    $project = auditProject();
    fakeTools();

    $this->artisan('audit', ['--resume' => null])
        ->expectsOutputToContain('No run to resume in')
        ->assertExitCode(3);

    expect(glob($project.'/storage/audit/*', GLOB_ONLYDIR))->toBe([]);
});

it('pauses at a usage limit, keeps the run on disk and resumes it later', function () {
    $project = auditProject();
    $answers = dependencyAnswers();
    fakeTools(['*pint*-p*' => fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.security'
        ? Process::result(claudeJson(['result' => $answers['dependencies.security']]))
        : Process::result(claudeJson(['subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'You have reached your weekly usage limit.']), exitCode: 1)]);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsOutputToContain('Usage limit reached')
        ->expectsConfirmation('Wait for the reset and continue?', 'no')
        ->assertExitCode(4);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('paused')
        ->and($run.'/tool/pause.json')->toBeFile()
        ->and(json_decode(file_get_contents($run.'/tool/pause.json'), true)['pending'])->toContain('dependencies.necessity');

    fakeAgent($answers);

    $this->artisan('audit', ['--resume' => null])->assertExitCode(0);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')
        ->and($run.'/tool/pause.json')->not->toBeFile();

    Process::assertRanTimes(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.security', 1);
});

it('waits for the reset when asked and continues the same run', function () {
    $project = auditProject();
    $slept = [];
    $this->app->bind(PauseRun::class, function () use (&$slept): PauseRun {
        return new PauseRun(function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });
    });
    $answers = dependencyAnswers();
    $calls = 0;
    fakeTools(['*pint*-p*' => function ($process) use ($answers, &$calls) {
        $task = $process->environment['AUDIT_TASK'] ?? '';

        return $task === 'dependencies.security' || ++$calls > 1
            ? Process::result(claudeJson(['result' => $answers[$task] ?? "## Findings\nNo findings.\n\n## Limitations\nNone.\n"]))
            : Process::result(claudeJson(['subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'Claude AI usage limit reached|'.(time() + 120)]), exitCode: 1);
    }]);

    $this->artisan('audit', ['--dependencies' => true])
        ->expectsConfirmation('Wait for the reset and continue?', 'yes')
        ->expectsOutputToContain('Resuming run')
        ->assertExitCode(0);

    expect($slept)->toHaveCount(1)
        ->and($slept[0])->toBeGreaterThan(60)
        ->and(RunFrontmatter::read(latestRun($project).'/run.md'))->status->toBe('completed');
});
