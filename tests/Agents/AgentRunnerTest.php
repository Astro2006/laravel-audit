<?php

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\Drivers\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\Drivers\OpenCodeDriver;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Illuminate\Support\Facades\Process;

function runner(string $logs, int $concurrency = 2): AgentRunner
{
    return new AgentRunner(new ClaudeCodeDriver, new ReportValidator(new ReportLinter(fixture('project'))), $logs, concurrency: $concurrency, pollMicroseconds: 1000);
}

function openCodeAnswerLine(string $text): string
{
    return implode("\n", [
        json_encode(['type' => 'text', 'timestamp' => 2, 'sessionID' => 'ses_slow', 'part' => ['messageID' => 'm1', 'text' => $text]]),
        json_encode(['type' => 'step_finish', 'timestamp' => 3, 'sessionID' => 'ses_slow', 'part' => ['messageID' => 'm1', 'reason' => 'stop', 'tokens' => ['input' => 10, 'output' => 5]]]),
    ])."\n";
}

function backendJob(array $required = []): AgentJob
{
    return new AgentJob('backend', AuditType::Backend, ReportSpec::forAudit(new BackendAudit), new AgentRequest('backend', 'Audit the backend.', fixture('project')), 'abc12345', $required);
}

function taskJob(string $key, array $dependsOn = [], ?Closure $request = null): AgentJob
{
    $spec = new ReportSpec($key, 'BE', ['other' => 'x'], ['Findings', 'Limitations']);

    return new AgentJob($key, AuditType::Backend, $spec, $request ?? new AgentRequest($key, 'Task '.$key, fixture('project')), 'h-'.$key, dependsOn: $dependsOn);
}

it('validates a good answer without a repair pass', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))]))]);
    $logs = tempDir('logs');

    [$outcome] = runner($logs)->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Completed)
        ->and($outcome->repaired)->toBeFalse()
        ->and($outcome->findings)->toHaveCount(2)
        ->and($outcome->model)->toBe('claude-opus-5')
        ->and($outcome->promptHash)->toBe('abc12345')
        ->and($outcome->finishedAt)->toBeGreaterThanOrEqual($outcome->startedAt)
        ->and($logs.'/backend.json')->toBeFile();

    Process::assertRanTimes(fn ($process) => $process->input === 'Audit the backend.' && $process->environment['AUDIT_TASK'] === 'backend', 1);
});

it('writes a JSON Lines answer as one formatted JSON document', function () {
    $stream = file_get_contents(fixture('agents/opencode-run.jsonl'))."{\"type\":\"tool_use\"\n";
    Process::fake(['*' => Process::result($stream)]);
    $logs = tempDir('logs');
    $runner = new AgentRunner(new OpenCodeDriver(fixture('project')), new ReportValidator(new ReportLinter(fixture('project'))), $logs, pollMicroseconds: 1000);

    $runner->run([backendJob()]);

    $events = json_decode(file_get_contents($logs.'/backend.json'), true);

    expect($events)->toBeList()
        ->and($events[0]['type'])->toBe('step_start')
        ->and(end($events))->toBe('{"type":"tool_use"')
        ->and(file_get_contents($logs.'/backend.json'))->toContain("\n    ");
});

it('stops an agent that runs past the timeout and fails its task', function () {
    Process::fake(['*' => Process::describe()->output('partial')->errorOutput('still thinking')->runsFor(iterations: 1000)]);
    $logs = tempDir('logs');
    $runner = new AgentRunner(new ClaudeCodeDriver, new ReportValidator(new ReportLinter(fixture('project'))), $logs, timeout: 0, pollMicroseconds: 1000);

    [$outcome] = $runner->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Failed)
        ->and($outcome->reason)->toContain('timed out after 0 seconds')
        ->and($outcome->repaired)->toBeFalse()
        ->and($logs.'/backend.json')->toBeFile();

    Process::assertRanTimes(fn () => true, 1);
});

it('asks an agent that ran out of time for its report in the same session', function () {
    $session = json_encode(['type' => 'step_start', 'timestamp' => 1, 'sessionID' => 'ses_slow', 'part' => []])."\n";
    Process::fake(['*' => Process::sequence()
        ->push(Process::describe()->output($session)->runsFor(iterations: 1000))
        ->push(Process::result(openCodeAnswerLine(file_get_contents(fixture('reports/backend-valid.md')))))]);
    $runner = new AgentRunner(new OpenCodeDriver(fixture('project')), new ReportValidator(new ReportLinter(fixture('project'))), tempDir('logs'), timeout: 0, pollMicroseconds: 1000);

    [$outcome] = $runner->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Completed)
        ->and($outcome->repaired)->toBeTrue();

    Process::assertRan(fn ($process) => in_array('ses_slow', (array) $process->command, true) && str_contains((string) $process->input, 'You ran out of time'));
});

it('removes the variables loaded from .env from the agent environment', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))]))]);
    $runner = new AgentRunner(new ClaudeCodeDriver, new ReportValidator(new ReportLinter(fixture('project'))), tempDir('logs'), pollMicroseconds: 1000, environment: new ChildEnvironment(['APP_KEY' => 'base64:x', 'DB_PASSWORD' => 'secret']));

    $runner->run([backendJob()]);

    Process::assertRan(fn ($process) => $process->environment['APP_KEY'] === false
        && $process->environment['DB_PASSWORD'] === false
        && $process->environment['CLAUDECODE'] === false
        && $process->environment['AUDIT_TASK'] === 'backend');
});

it('repairs an invalid answer in the same session', function () {
    $broken = str_replace("## Limitations\nQueues were not inspected.\n", '', file_get_contents(fixture('reports/backend-valid.md')));

    Process::fake(['*' => Process::sequence()
        ->push(Process::result(claudeJson(['result' => $broken])))
        ->push(Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md')), 'total_cost_usd' => 0.08, 'num_turns' => 1])))]);
    $logs = tempDir('logs');

    [$outcome] = runner($logs)->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Completed)
        ->and($outcome->repaired)->toBeTrue()
        ->and($outcome->telemetry->costUsd)->toBe(0.5)
        ->and($outcome->telemetry->turns)->toBe(13)
        ->and($logs.'/backend.repair.json')->toBeFile();

    Process::assertRan(fn ($process) => in_array('--resume', (array) $process->command, true)
        && str_contains($process->input, 'Missing section `## Limitations`.')
        && str_contains($process->input, 'starting with the `## Summary` line'));
});

it('asks for the report once the turn limit is reached', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(claudeJson(['subtype' => 'error_max_turns', 'is_error' => true, 'result' => ''])))
        ->push(Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))])))]);

    [$outcome] = runner(tempDir('logs'))->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Completed);

    Process::assertRan(fn ($process) => in_array('--resume', (array) $process->command, true)
        && str_contains($process->input, 'You reached the turn limit.'));
});

it('asks an agent that never opened a file to check its findings once', function () {
    $valid = file_get_contents(fixture('reports/backend-valid.md'));
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(claudeJson(['result' => $valid, 'num_turns' => 1])))
        ->push(Process::result(claudeJson(['result' => $valid, 'num_turns' => 4])))]);

    [$outcome] = runner(tempDir('logs'))->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Completed)
        ->and($outcome->repaired)->toBeTrue();

    Process::assertRan(fn ($process) => in_array('--resume', (array) $process->command, true)
        && str_contains($process->input, 'without opening a single file'));
});

it('fails after an unsuccessful repair pass', function () {
    $broken = str_replace('## Summary', '## Overview', file_get_contents(fixture('reports/backend-valid.md')));
    Process::fake(['*' => Process::result(claudeJson(['result' => $broken]))]);

    [$outcome] = runner(tempDir('logs'))->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Failed)
        ->and($outcome->reason)->toBe('Report failed validation after the repair pass.')
        ->and($outcome->errors)->toContain('Missing section `## Summary`.');
});

it('does not repair failed or truncated agent runs', function () {
    Process::fake(['*' => Process::result(claudeJson(['subtype' => 'error_max_budget_usd', 'is_error' => true, 'result' => '']), exitCode: 1)]);

    [$outcome] = runner(tempDir('logs'))->run([backendJob()]);

    expect($outcome->status)->toBe(AuditStatus::Failed)
        ->and($outcome->reason)->toContain('error_max_budget_usd');

    Process::assertRanTimes(fn () => true, 1);
});

it('asks for missing required findings in the repair pass', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))]))]);

    [$outcome] = runner(tempDir('logs'))->run([backendJob([new RequiredFinding('sql-injection', 'the SQL injection from the facts.')])]);

    expect($outcome->status)->toBe(AuditStatus::Failed);
    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'Missing required finding: the SQL injection from the facts.'));
});

it('starts a task only after the tasks it depends on and hands their outcomes over', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => "## Findings\nNo findings.\n\n## Limitations\nNone."]))]);
    $started = [];
    $seen = null;

    $jobs = [
        taskJob('backend.summary', ['backend.conventions', 'backend.injection'], function (array $finished) use (&$seen): AgentRequest {
            $seen = array_keys($finished);

            return new AgentRequest('backend.summary', 'Summarize', fixture('project'));
        }),
        taskJob('backend.conventions'),
        taskJob('backend.injection'),
    ];

    $outcomes = runner(tempDir('logs'), concurrency: 4)->run($jobs, function (AgentJob $job) use (&$started) {
        $started[] = $job->key;
    });

    expect($started[2])->toBe('backend.summary')
        ->and($seen)->toEqualCanonicalizing(['backend.conventions', 'backend.injection'])
        ->and(array_map(fn (TaskOutcome $outcome) => $outcome->key, $outcomes))->toHaveCount(3)
        ->and($outcomes[2]->key)->toBe('backend.summary');
});

it('ignores dependencies outside the run and fails tasks stuck in a cycle', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => "## Findings\nNo findings.\n\n## Limitations\nNone."]))]);

    $outcomes = runner(tempDir('logs'))->run([
        taskJob('backend.laravel', ['backend.not-selected']),
        taskJob('backend.a', ['backend.b']),
        taskJob('backend.b', ['backend.a']),
    ]);
    $byKey = collect($outcomes)->keyBy('key');

    expect($byKey['backend.laravel']->status)->toBe(AuditStatus::Completed)
        ->and($byKey['backend.a']->status)->toBe(AuditStatus::Failed)
        ->and($byKey['backend.a']->reason)->toBe('Waiting for tasks that never finished: backend.b.');

    Process::assertRanTimes(fn () => true, 1);
});

it('runs one task at a time with a concurrency of 1', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => "## Findings\nNo findings.\n\n## Limitations\nNone."]))]);
    $events = [];

    runner(tempDir('logs'), concurrency: 1)->run(
        [taskJob('backend.a'), taskJob('backend.b')],
        function (AgentJob $job) use (&$events) {
            $events[] = 'start '.$job->key;
        },
        function (TaskOutcome $outcome) use (&$events) {
            $events[] = 'finish '.$outcome->key;
        },
    );

    expect($events)->toBe(['start backend.a', 'finish backend.a', 'start backend.b', 'finish backend.b']);
});

it('starts the jobs others wait on first', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => "## Findings\nNo findings.\n\n## Limitations\nNone."]))]);
    $started = [];

    runner(tempDir('logs'), concurrency: 1)->run(
        [taskJob('backend.a'), taskJob('backend.b'), taskJob('backend.c', dependsOn: ['backend.b'])],
        function (AgentJob $job) use (&$started) {
            $started[] = $job->key;
        },
    );

    expect($started)->toBe(['backend.b', 'backend.a', 'backend.c']);
});

it('reports every poll round and the start of a repair pass', function () {
    $broken = str_replace("## Limitations\nQueues were not inspected.\n", '', file_get_contents(fixture('reports/backend-valid.md')));

    Process::fake(['*' => Process::sequence()
        ->push(Process::describe()->output(claudeJson(['result' => $broken]))->iterations(3))
        ->push(Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))])))]);
    $ticks = 0;
    $repairs = [];

    runner(tempDir('logs'))->run(
        [backendJob()],
        onTick: function () use (&$ticks) {
            $ticks++;
        },
        onRepair: function (AgentJob $job) use (&$repairs) {
            $repairs[] = $job->key;
        },
    );

    expect($ticks)->toBeGreaterThanOrEqual(3)
        ->and($repairs)->toBe(['backend']);
});
