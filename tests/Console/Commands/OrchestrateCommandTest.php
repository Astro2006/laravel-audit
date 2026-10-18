<?php

use Astro2006\LaravelAudit\Run\Data\RunFrontmatter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Fakes the agent CLI: the orchestrator session drives audit:tool itself
 * through $drive, the task sessions answer per task.
 */
function fakeOrchestrator(Closure $drive, array $answers): void
{
    fakeTools(['*pint*-p*' => function ($process) use ($drive, $answers) {
        $task = $process->environment['AUDIT_TASK'] ?? '';

        if ($task === 'orchestrator') {
            preg_match('/--run=([\w-]+)/', (string) $process->input, $match);

            return Process::result(claudeJson(['result' => $drive($match[1]), 'total_cost_usd' => 0.05]));
        }

        return Process::result(claudeJson(['result' => $answers[$task] ?? "## Findings\nNo findings.\n\n## Limitations\nNone.\n"]));
    }]);
}

it('lets a headless agent drive the audit through audit:tool and keeps its closing message', function () {
    $project = auditProject();
    fakeOrchestrator(function (string $run): string {
        Artisan::call('audit:tool', ['action' => 'preflight', 'items' => ['composer'], '--run' => $run, '--sync' => true]);
        Artisan::call('audit:tool', ['action' => 'start', '--stage' => 'analyse', '--run' => $run, '--sync' => true]);
        Artisan::call('audit:tool', ['action' => 'finish', '--run' => $run]);

        return 'Fix DEP-001 first. No task failed.';
    }, dependencyAnswers());

    $this->artisan('audit:orchestrate', ['--audits' => ['dependencies']])
        ->expectsOutputToContain('drives the audit through audit:tool')
        ->assertExitCode(0);

    $run = latestRun($project);

    expect(file_get_contents($run.'/orchestrator.md'))->toContain('Fix DEP-001 first.')
        ->and(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')
        ->and(file_get_contents($run.'/dependencies.md'))->toContain('### DEP-001 — guzzlehttp/psr7')
        ->and(file_get_contents($run.'/logs/orchestrator.prompt.md'))->toContain('php artisan audit:tool catalog --run='.basename($run))
        ->toContain('## Stages');

    Process::assertRan(fn ($process) => ($process->environment['AUDIT_TASK'] ?? '') === 'orchestrator'
        && in_array('Bash(php artisan audit:tool:*)', (array) $process->command, true)
        && str_contains(implode(' ', (array) $process->command), '--max-turns 300'));
});

it('writes the reports itself when the agent ends without finishing', function () {
    $project = auditProject();
    fakeOrchestrator(function (string $run): string {
        Artisan::call('audit:tool', ['action' => 'preflight', 'items' => ['composer'], '--run' => $run, '--sync' => true]);

        return 'I ran out of ideas.';
    }, dependencyAnswers());

    $this->artisan('audit:orchestrate', ['--audits' => ['dependencies']])->assertExitCode(0);

    $run = latestRun($project);

    expect(RunFrontmatter::read($run.'/run.md'))->status->toBe('completed')
        ->and(file_get_contents($run.'/dependencies.md'))->toContain('Not started in this run.');
});

it('fails when the agent never ran the preflight', function () {
    $project = auditProject();
    fakeOrchestrator(fn (string $run): string => 'Nothing.', []);

    $this->artisan('audit:orchestrate', ['--audits' => ['dependencies']])
        ->expectsOutputToContain('There is no preflight yet')
        ->assertExitCode(3);

    expect(glob(latestRun($project).'/*.md'))->toBe([latestRun($project).'/orchestrator.md', latestRun($project).'/run.md']);
});
