<?php

use Astro2006\LaravelAudit\Agents\Drivers\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\Drivers\OpenCodeDriver;
use Astro2006\LaravelAudit\Agents\ModelProbe;
use Illuminate\Support\Facades\Process;

/**
 * Fakes the agent CLI: the model check fails for every model in $unknown,
 * the tasks answer as in fakeAgent().
 *
 * @param  list<string>  $unknown
 */
function fakeModels(array $unknown, ?array $answers = null): void
{
    config()->set('audit.agent.check_model', true);
    $answers ??= dependencyAnswers();

    fakeTools(['*pint*-p*' => function ($process) use ($unknown, $answers) {
        $command = (array) $process->command;
        $model = in_array('--model', $command, true) ? $command[array_search('--model', $command, true) + 1] : null;
        $task = $process->environment['AUDIT_TASK'] ?? '';

        if ($task === ModelProbe::KEY && in_array($model, $unknown, true)) {
            return Process::result(claudeJson(['is_error' => true, 'result' => "There's an issue with the selected model ({$model}). It may not exist or you may not have access to it."]));
        }

        return Process::result(claudeJson(['result' => $answers[$task] ?? 'OK']));
    }]);
}

function taskRanWith(string $model): Closure
{
    return fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'dependencies.security' && in_array($model, (array) $process->command, true);
}

it('checks that the model answers before the preflight starts', function () {
    auditProject();
    fakeModels([]);

    $this->artisan('audit', ['--dependencies' => true, '--model' => 'haiku'])
        ->expectsOutputToContain('Model haiku answers')
        ->assertExitCode(0);

    Process::assertRanTimes(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === ModelProbe::KEY, 1);
});

it('offers the models the CLI knows when the chosen one does not answer and runs with the pick', function () {
    $project = auditProject();
    fakeModels(['claude-nope']);

    $this->artisan('audit', ['--dependencies' => true, '--model' => 'claude-nope'])
        ->expectsOutputToContain('Model claude-nope does not answer: There\'s an issue with the selected model (claude-nope).')
        ->expectsChoice('Pick a model for this run', 'sonnet', ['fable', 'opus', 'sonnet', 'haiku', 'None, stop the run'])
        ->expectsOutputToContain('Model sonnet answers')
        ->assertExitCode(0);

    Process::assertRan(taskRanWith('sonnet'));
    Process::assertNotRan(taskRanWith('claude-nope'));

    expect(is_dir(latestRun($project).'/preflight'))->toBeTrue();
});

it('stops before the preflight when the pick is none', function () {
    $project = auditProject();
    fakeModels(['claude-nope']);

    $this->artisan('audit', ['--dependencies' => true, '--model' => 'claude-nope'])
        ->expectsChoice('Pick a model for this run', 'None, stop the run', ['fable', 'opus', 'sonnet', 'haiku', 'None, stop the run'])
        ->assertExitCode(3);

    expect(glob($project.'/storage/audit/*', GLOB_ONLYDIR))->toBe([]);
});

it('lists the models and stops without asking when there is no one to ask', function () {
    $project = auditProject();
    fakeModels(['claude-nope']);

    $this->artisan('audit', ['--dependencies' => true, '--model' => 'claude-nope', '--no-interaction' => true])
        ->expectsOutputToContain('Models the CLI offers: fable, opus, sonnet, haiku')
        ->expectsOutputToContain('Start again with --model=<model>')
        ->assertExitCode(3);

    expect(glob($project.'/storage/audit/*', GLOB_ONLYDIR))->toBe([]);
});

it('does not check the model for a preflight-only run', function () {
    auditProject();
    fakeModels(['claude-nope']);

    $this->artisan('audit', ['--dependencies' => true, '--model' => 'claude-nope', '--preflight-only' => true])
        ->assertExitCode(0);

    Process::assertNotRan(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === ModelProbe::KEY);
});

it('counts a usage limit as an answer and leaves the pause to the run', function () {
    auditProject();
    config()->set('audit.agent.check_model', true);
    fakeTools(['*pint*-p*' => fn () => Process::result(claudeJson(['subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'You have reached your weekly usage limit.']), exitCode: 1)]);

    $this->artisan('audit', ['--dependencies' => true, '--no-interaction' => true])
        ->expectsOutputToContain('answers')
        ->expectsOutputToContain('Usage limit reached')
        ->assertExitCode(4);
});

it('checks the orchestrator model when it differs from the task model', function () {
    $project = auditProject();
    fakeModels(['orchestrator-nope']);
    config()->set('audit.agent.orchestrator.model', 'orchestrator-nope');

    $this->artisan('audit:orchestrate', ['--audits' => ['dependencies'], '--model' => 'haiku', '--no-interaction' => true])
        ->expectsOutputToContain('Model haiku answers')
        ->expectsOutputToContain('Model orchestrator-nope does not answer')
        ->assertExitCode(3);

    expect(glob($project.'/storage/audit/*', GLOB_ONLYDIR))->toBe([]);
});

it('reads the models opencode lists and offers the claude aliases', function () {
    $listed = "opencode/big-pickle\ncodebar/qwen3-coder:30b\n\nINFO some log line\n  ollama/qwen3.5:9b  \n";

    expect((new OpenCodeDriver('/app'))->models($listed))->toBe(['opencode/big-pickle', 'codebar/qwen3-coder:30b', 'ollama/qwen3.5:9b'])
        ->and((new OpenCodeDriver('/app', 'opencode'))->modelsCommand())->toBe(['opencode', 'models', '--pure'])
        ->and((new ClaudeCodeDriver)->modelsCommand())->toBeNull()
        ->and((new ClaudeCodeDriver)->models(''))->toBe(['fable', 'opus', 'sonnet', 'haiku']);
});
