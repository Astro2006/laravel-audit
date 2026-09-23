<?php

use Astro2006\LaravelAudit\Contracts\Orchestrator;
use Astro2006\LaravelAudit\Data\Run\RunFrontmatter;
use Astro2006\LaravelAudit\Orchestration\AgentOrchestrator;
use Astro2006\LaravelAudit\Orchestration\AuditOrchestrator;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Orchestration\OrchestrationState;
use Astro2006\LaravelAudit\Orchestration\Tools\Catalog;
use Astro2006\LaravelAudit\Orchestration\Tools\StartWork;
use Astro2006\LaravelAudit\Orchestration\Tools\Work;
use Astro2006\LaravelAudit\Orchestration\WorkerLock;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\AuditConfig;
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

/** A run as `audit --orchestrate` starts it, for the dependencies audit. */
function orchestratedRun(string $project): AuditRun
{
    $run = AuditRun::create($project.'/storage/audit');
    (new OrchestrationState(['dependencies'], [], false, 'opencode', null, null))->write(OrchestratedRun::directory($run));

    return $run;
}

/** @return array<string, mixed> */
function tool(AuditRun $run, string $action, array $items = [], array $options = []): array
{
    Artisan::call('audit:tool', ['action' => $action, 'items' => $items, '--run' => $run->id, ...$options]);

    return json_decode(Artisan::output(), true);
}

/** Answers the task agents by task and runs $orchestrator as the orchestrator session. */
function fakeOrchestration(Closure $orchestrator): void
{
    $answers = dependencyAnswers();

    fakeTools([
        '*pint*run*' => fn ($process) => Process::result(openCodeAnswer(in_array('laravel-audit-orchestrator', (array) $process->command, true)
            ? $orchestrator($process->environment['AUDIT_RUN'])
            : $answers[$process->environment['AUDIT_TASK']] ?? "## Findings\nNo findings.\n\n## Limitations\nNone.\n")),
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

    (new WorkerLock(OrchestratedRun::directory($run).'/worker.lock'))->acquire(WorkerLock::TASKS, ['dependencies.security']);

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
    fakeOrchestration(fn () => '');
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

it('lets the orchestrator agent pick the steps and tasks, then writes the reports', function () {
    $project = orchestratedProject();
    // The command's output is mocked while `audit` runs, so the session calls the tools directly.
    fakeOrchestration(function (string $runId): string {
        $config = app(AuditConfig::class);
        $run = OrchestratedRun::open($config, $runId);
        $start = new StartWork($config, new Work($config));
        (new Catalog($config))->handle($run);
        $start->preflight($run, ['composer'], sync: true);
        $start->tasks($run, ['dependencies.security', 'dependencies.necessity'], sync: true);

        return 'Ran composer, security and necessity. Left out freshness and hygiene.';
    });

    $this->artisan('audit', ['--dependencies' => true, '--orchestrate' => true])
        ->expectsOutputToContain('Orchestrator')
        ->expectsOutputToContain('dependencies.security · 3 findings')
        ->expectsOutputToContain('Left out freshness and hygiene.')
        ->expectsOutputToContain('dependencies.freshness · skipped: Not started by the orchestrator.')
        ->assertExitCode(0);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')
        ->and(file_get_contents($run.'/dependencies.md'))->toContain('### DEP-001 — guzzlehttp/psr7')
        ->and($run.'/logs/orchestrator.prompt.md')->toBeFile()
        ->and(file_get_contents($run.'/logs/orchestrator.prompt.md'))->toContain('php artisan audit:tool catalog')
        ->and($run.'/orchestration/answer.md')->toBeFile()
        ->and($run.'/preflight/summary.json')->toBeFile();

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'laravel-audit-orchestrator')
        && $process->environment['AUDIT_RUN'] === basename($run)
        && str_ends_with($process->environment['OPENCODE_CONFIG'], 'logs/opencode-orchestrator.json'));
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'artisan test'));
});

it('picks the orchestrator from the config', function () {
    orchestratedProject();

    expect(app(Orchestrator::class))->toBeInstanceOf(AuditOrchestrator::class);

    config()->set('audit.orchestrator', 'agent');

    expect(app(Orchestrator::class))->toBeInstanceOf(AgentOrchestrator::class);
});

it('only orchestrates with OpenCode for now', function () {
    orchestratedProject();
    config()->set('audit.agent.driver', 'claude_code');
    fakeTools();

    $this->artisan('audit', ['--dependencies' => true, '--orchestrate' => true])
        ->expectsOutputToContain('--orchestrate needs OpenCode for now')
        ->assertExitCode(3);
});
