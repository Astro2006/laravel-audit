<?php

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Illuminate\Support\Facades\Process;

function runner(string $logs): AgentRunner
{
    return new AgentRunner(new ClaudeCodeDriver, new ReportValidator(new ReportLinter(fixture('project'))), $logs, concurrency: 2, pollMicroseconds: 1000);
}

function backendJob(array $required = []): AgentJob
{
    return new AgentJob(new BackendAudit, new AgentRequest('backend', 'Audit the backend.', fixture('project')), 'abc12345', $required);
}

it('validates a good answer without a repair pass', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))]))]);
    $logs = tempDir('logs');

    $outcomes = runner($logs)->run([backendJob()]);

    expect($outcomes['backend']->status)->toBe(AuditStatus::Completed)
        ->and($outcomes['backend']->repaired)->toBeFalse()
        ->and($outcomes['backend']->findings)->toHaveCount(2)
        ->and($outcomes['backend']->model)->toBe('claude-opus-5')
        ->and($outcomes['backend']->promptHash)->toBe('abc12345')
        ->and($logs.'/backend.json')->toBeFile();

    Process::assertRanTimes(fn ($process) => $process->input === 'Audit the backend.', 1);
});

it('repairs an invalid answer in the same session', function () {
    $broken = str_replace("## Limitations\nQueues were not inspected.\n", '', file_get_contents(fixture('reports/backend-valid.md')));

    Process::fake(['*' => Process::sequence()
        ->push(Process::result(claudeJson(['result' => $broken])))
        ->push(Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md')), 'total_cost_usd' => 0.08, 'num_turns' => 1])))]);
    $logs = tempDir('logs');

    $outcome = runner($logs)->run([backendJob()])['backend'];

    expect($outcome->status)->toBe(AuditStatus::Completed)
        ->and($outcome->repaired)->toBeTrue()
        ->and($outcome->telemetry->costUsd)->toBe(0.5)
        ->and($outcome->telemetry->turns)->toBe(13)
        ->and($logs.'/backend.repair.json')->toBeFile();

    Process::assertRan(fn ($process) => in_array('--resume', (array) $process->command, true)
        && str_contains($process->input, 'Missing section `## Limitations`.'));
});

it('fails after an unsuccessful repair pass', function () {
    $broken = str_replace('## Summary', '## Overview', file_get_contents(fixture('reports/backend-valid.md')));
    Process::fake(['*' => Process::result(claudeJson(['result' => $broken]))]);

    $outcome = runner(tempDir('logs'))->run([backendJob()])['backend'];

    expect($outcome->status)->toBe(AuditStatus::Failed)
        ->and($outcome->reason)->toBe('Report failed validation after the repair pass.')
        ->and($outcome->errors)->toContain('Missing section `## Summary`.');
});

it('does not repair failed or truncated agent runs', function () {
    Process::fake(['*' => Process::result(claudeJson(['subtype' => 'error_max_budget_usd', 'is_error' => true, 'result' => '']), exitCode: 1)]);

    $outcome = runner(tempDir('logs'))->run([backendJob()])['backend'];

    expect($outcome->status)->toBe(AuditStatus::Failed)
        ->and($outcome->reason)->toContain('error_max_budget_usd');

    Process::assertRanTimes(fn () => true, 1);
});

it('asks for missing required findings in the repair pass', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => file_get_contents(fixture('reports/backend-valid.md'))]))]);

    $outcome = runner(tempDir('logs'))->run([backendJob([new RequiredFinding('sql-injection', 'the SQL injection from the facts.')])])['backend'];

    expect($outcome->status)->toBe(AuditStatus::Failed);
    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'Missing required finding: the SQL injection from the facts.'));
});

it('runs several audits and reports each when it finishes', function () {
    Process::fake(['*' => Process::result(claudeJson(['result' => "## Summary\nx"]))]);
    $finished = [];

    $jobs = [backendJob(), new AgentJob(new DependenciesAudit, new AgentRequest('dependencies', 'Deps', fixture('project')), 'h')];
    $outcomes = runner(tempDir('logs'))->run($jobs, onFinished: function ($outcome) use (&$finished) {
        $finished[] = $outcome->audit->value;
    });

    expect(array_keys($outcomes))->toEqualCanonicalizing(['backend', 'dependencies'])
        ->and($finished)->toEqualCanonicalizing(['backend', 'dependencies']);
});
