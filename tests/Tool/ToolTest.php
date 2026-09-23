<?php

use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\Data\RunFrontmatter;
use Astro2006\LaravelAudit\Tool\OpenedRun;
use Astro2006\LaravelAudit\Tool\RunState;
use Astro2006\LaravelAudit\Tool\WorkerLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/** An OpenCode event stream: one tool round, then the answer. */
function openCodeAnswer(string $text): string
{
    $event = fn (string $type, array $part): string => json_encode(['type' => $type, 'timestamp' => 1789134344138, 'sessionID' => 'ses_1', 'part' => ['messageID' => 'msg_'.($part['reason'] ?? 'text'), ...$part]]);

    return implode("\n", [
        $event('step_finish', ['reason' => 'tool-calls', 'messageID' => 'msg_1', 'tokens' => ['input' => 100, 'output' => 10]]),
        $event('text', ['messageID' => 'msg_2', 'text' => $text]),
        $event('step_finish', ['reason' => 'stop', 'messageID' => 'msg_2', 'tokens' => ['input' => 200, 'output' => 50]]),
    ])."\n";
}

/** The project with OpenCode as the agent CLI (pint stands in for it). */
function orchestratedProject(): string
{
    $project = auditProject();
    config()->set('audit.agent.driver', 'opencode');
    config()->set('audit.agent.drivers.opencode.binary', $project.'/vendor/bin/pint');

    return $project;
}

/** A run opened for audit:tool, for the dependencies audit. */
function orchestratedRun(string $project): AuditRun
{
    $run = AuditRun::create($project.'/storage/audit');
    (new RunState(['dependencies'], [], false, 'opencode', null, null))->write(OpenedRun::directory($run));

    return $run;
}

/** @return array<string, mixed> */
function tool(AuditRun $run, string $action, array $items = [], array $options = []): array
{
    Artisan::call('audit:tool', ['action' => $action, 'items' => $items, '--run' => $run->id, ...$options]);

    return json_decode(Artisan::output(), true);
}

/** Answers the task agents by task. */
function fakeTaskAgents(): void
{
    $answers = dependencyAnswers();

    fakeTools([
        '*pint*run*' => fn ($process) => Process::result(openCodeAnswer($answers[$process->environment['AUDIT_TASK']] ?? "## Findings\nNo findings.\n\n## Limitations\nNone.\n")),
    ]);
}

it('lists the tasks and preflight steps of the run', function () {
    $project = orchestratedProject();
    fakeTools();
    $catalog = tool(orchestratedRun($project), 'catalog');

    expect($catalog['audits'])->toBe(['dependencies'])
        ->and(array_keys($catalog['tasks']))->toBe(['dependencies.security', 'dependencies.necessity', 'dependencies.freshness', 'dependencies.hygiene'])
        ->and($catalog['tasks']['dependencies.security'])->toMatchArray(['status' => 'not_started', 'reads_steps' => ['about', 'stack', 'loc', 'composer']])
        ->and($catalog['preflight_steps']['tests'])->toBe(['heavy' => true, 'configured_for' => [], 'status' => 'not_run'])
        ->and($catalog['preflight_steps']['composer'])->toBe(['heavy' => false, 'configured_for' => ['dependencies'], 'status' => 'not_run']);
});

it('runs only the requested preflight steps and keeps them across calls', function () {
    $project = orchestratedProject();
    fakeTools();
    $run = orchestratedRun($project);

    expect(tool($run, 'preflight', ['composer'], ['--sync' => true]))->toBe(['started' => ['about', 'stack', 'loc', 'composer']])
        ->and(tool($run, 'preflight', ['composer'], ['--sync' => true]))->toBe(['started' => [], 'already_done' => ['composer']])
        ->and(tool($run, 'preflight', ['routes'], ['--sync' => true]))->toBe(['started' => ['routes']]);

    $status = tool($run, 'status');

    expect(array_keys($status['preflight']))->toBe(['about', 'stack', 'loc', 'routes', 'composer'])
        ->and($status['worker'])->toBe('idle')
        ->and($status['gate'])->toBe('not-run');

    Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'artisan test'));
});

it('refuses work it cannot start', function () {
    $project = orchestratedProject();
    fakeTools();
    $run = orchestratedRun($project);

    expect(tool($run, 'start', ['dependencies.security']))->error->toBe('There is no preflight yet.')
        ->and(tool($run, 'preflight', ['nope']))->error->toBe('Unknown preflight step "nope".');

    tool($run, 'preflight', ['composer'], ['--sync' => true]);

    expect(tool($run, 'start', ['backend.injection']))->error->toBe('Unknown task "backend.injection".');

    (new WorkerLock(OpenedRun::directory($run).'/worker.lock'))->acquire(WorkerLock::TASKS, ['dependencies.security']);

    expect(tool($run, 'start', ['dependencies.necessity']))->toMatchArray(['error' => 'busy', 'working_on' => 'tasks', 'items' => ['dependencies.security']])
        ->and(tool($run, 'wait', options: ['--timeout' => 1]))->toMatchArray(['done' => false])
        ->and(tool($run, 'status')['tasks']['dependencies.security'])->toBe(['status' => 'running']);
});

it('starts the worker in the background and returns right away', function () {
    $project = orchestratedProject();
    fakeTools(['*nohup*' => Process::result('')]);
    $run = orchestratedRun($project);

    $started = tool($run, 'preflight', ['composer']);

    expect($started)->toBe(['started' => ['about', 'stack', 'loc', 'composer'], 'hint' => 'Running in the background. Run `wait` to block until it is done.'])
        ->and(tool($run, 'status')['worker'])->toMatchArray(['working_on' => 'preflight', 'items' => ['about', 'stack', 'loc', 'composer']]);

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'nohup')
        && str_contains(implode(' ', (array) $process->command), "audit:tool work --run='".$run->id."'")
        && str_contains(implode(' ', (array) $process->command), 'worker-preflight.log'));
});

it('runs tasks and returns their findings', function () {
    $project = orchestratedProject();
    fakeTaskAgents();
    $run = orchestratedRun($project);
    tool($run, 'preflight', ['composer'], ['--sync' => true]);

    $started = tool($run, 'start', ['dependencies.necessity', 'dependencies.security'], ['--sync' => true]);
    $result = tool($run, 'result', ['dependencies.security']);

    expect($started)->toBe(['started' => ['dependencies.necessity', 'dependencies.security']])
        ->and($result['status'])->toBe('completed')
        ->and($result['findings'])->toHaveCount(3)
        ->and($result['findings'][0])->toHaveKeys(['id', 'severity', 'confidence', 'check', 'locations'])
        ->and(tool($run, 'status')['tasks'])->toMatchArray([
            'dependencies.freshness' => ['status' => 'not_started'],
        ])
        ->and(tool($run, 'result', ['dependencies.hygiene']))->error->toBe('Task "dependencies.hygiene" has no result yet.');
});

/** @return array<string, mixed> */
function openRun(array $options = []): array
{
    Artisan::call('audit:tool', ['action' => 'open', ...$options]);

    return json_decode(Artisan::output(), true);
}

it('opens a run for the chosen audits', function () {
    $project = orchestratedProject();
    fakeTools();

    $opened = openRun(['--audits' => ['dependencies']]);

    expect($opened)->toMatchArray(['audits' => ['dependencies'], 'agent' => 'OpenCode', 'concurrency' => 1])
        ->and($opened['next'])->toBe('php artisan audit:tool catalog --run='.$opened['run'])
        ->and($project.'/storage/audit/'.$opened['run'].'/run.md')->toBeFile()
        ->and(tool(AuditRun::open($project.'/storage/audit', $opened['run']), 'catalog')['audits'])->toBe(['dependencies']);
});

it('refuses to open a run with unknown audits', function () {
    orchestratedProject();
    fakeTools();

    expect(openRun(['--audits' => ['nope']]))->toHaveKey('error');
});

it('writes the reports of the tasks that ran when the session finishes', function () {
    $project = orchestratedProject();
    fakeTaskAgents();
    $run = AuditRun::open($project.'/storage/audit', openRun(['--audits' => ['dependencies']])['run']);

    tool($run, 'preflight', ['composer'], ['--sync' => true]);
    tool($run, 'start', ['dependencies.security', 'dependencies.necessity'], ['--sync' => true]);
    $finished = tool($run, 'finish');

    expect($finished)->toMatchArray(['status' => 'completed'])
        ->and($finished['audits'][0])->toMatchArray(['audit' => 'dependencies', 'status' => 'completed'])
        ->and(RunFrontmatter::read($run->manifestPath()))->status->toBe('completed')
        ->and(file_get_contents($run->dir().'/dependencies.md'))->toContain('### DEP-001 — guzzlehttp/psr7')
        ->and(file_get_contents($run->dir().'/dependencies.md'))->toContain('dependencies.freshness');
});

it('stops at the test gate until the user agrees to continue', function () {
    $project = orchestratedProject();
    fakeTools(['*artisan*test*--log-junit*' => fn ($process) => redTestRun($process->command)]);
    config()->set('audit.audits.dependencies.preflight', ['about', 'stack', 'composer', 'tests']);
    $run = AuditRun::open($project.'/storage/audit', openRun(['--audits' => ['dependencies']])['run']);

    tool($run, 'preflight', ['composer', 'tests'], ['--sync' => true]);
    $status = tool($run, 'status');

    expect($status)->toMatchArray(['gate' => 'aborted', 'failing_tests' => 1])
        ->and($status['gate_hint'])->toContain('ask whether to continue')
        ->and(tool($run, 'start', ['dependencies.security']))->error->toContain('test gate')
        ->and(tool($run, 'continue'))->toBe(['gate' => 'forced', 'next' => 'Start the tasks with `start <task> …`.'])
        ->and(tool($run, 'status')['gate'])->toBe('forced')
        ->and(tool($run, 'continue'))->toHaveKey('error');
});

it('does not finish while the worker runs or before the preflight', function () {
    $project = orchestratedProject();
    fakeTools();
    $run = AuditRun::open($project.'/storage/audit', openRun(['--audits' => ['dependencies']])['run']);

    expect(tool($run, 'finish'))->error->toBe('There is no preflight yet, so there is nothing to report.');

    (new WorkerLock(OpenedRun::directory($run).'/worker.lock'))->acquire(WorkerLock::TASKS, ['dependencies.security']);

    expect(tool($run, 'finish'))->error->toBe('busy');
});

it('lists the installed skills and hands the chosen ones to the task', function () {
    $project = orchestratedProject();
    @mkdir($project.'/.claude/skills/composer-rules', 0777, true);
    file_put_contents($project.'/.claude/skills/composer-rules/SKILL.md', "---\nname: composer-rules\ndescription: How this team pins Composer packages.\n---\nPin every package to a caret constraint.\n");
    fakeTaskAgents();
    $run = AuditRun::open($project.'/storage/audit', openRun(['--audits' => ['dependencies']])['run']);

    expect(tool($run, 'catalog'))->toMatchArray(['skills' => ['composer-rules' => 'How this team pins Composer packages.'], 'skills_per_task' => 2]);

    tool($run, 'preflight', ['composer'], ['--sync' => true]);
    $started = tool($run, 'start', ['dependencies.hygiene:composer-rules,made-up'], ['--sync' => true]);

    expect($started['started'])->toBe(['dependencies.hygiene'])
        ->and($started['warnings'])->toBe(['Skills left out: unknown skill "made-up" for dependencies.hygiene.'])
        ->and(file_get_contents($run->logsDir().'/dependencies.hygiene.prompt.md'))->toContain("### Skill `composer-rules`\n\nPin every package to a caret constraint.");
});
