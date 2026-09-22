<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;
use Astro2006\LaravelAudit\Agents\OrchestrationAgent;
use Astro2006\LaravelAudit\Agents\TaskLog;
use Astro2006\LaravelAudit\Console\TaskBoard;
use Astro2006\LaravelAudit\Contracts\OrchestratingDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Orchestration\OrchestrationState;
use Astro2006\LaravelAudit\Orchestration\RunContext;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;

final class RunOrchestrator
{
    /** @var array<string, true> preflight steps and jobs already shown */
    private array $shown = [];

    public function __construct(
        private readonly AuditConfig $config,
        private readonly int $pollMicroseconds = 500_000,
    ) {}

    public function handle(RunContext $context, OrchestratingDriver $driver): OrchestratedRun
    {
        $plan = $context->plan;
        $run = $context->run;
        $console = $context->console;
        $state = OrchestrationState::fromOptions($context->options, $plan);
        $directory = OrchestratedRun::directory($run);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $state->write($directory);
        $orchestrated = OrchestratedRun::for($run, $state, $plan);

        $extra = str_starts_with($run->dir(), rtrim($plan->project->basePath, '/').'/') ? [] : [$run->outputRoot];
        $maxTurns = $this->config->int('agent.orchestrator.max_turns');
        $prompt = (new OrchestrationAgent(PromptTemplates::publishedPath()))->render(
            (string) config('app.name', 'Laravel'),
            $run->id,
            $state->audits,
            $state->force,
            $this->config->int('agent.orchestrator.wait_timeout'),
            $console->messages->locale,
        );
        file_put_contents($run->logsDir().'/'.OrchestrationAgent::KEY.'.prompt.md', $prompt->text);

        $request = new AgentRequest(
            OrchestrationAgent::KEY,
            $prompt->text,
            $plan->project->basePath,
            $driver->writeOrchestratorConfig($run->logsDir(), $extra, $maxTurns > 0 ? $maxTurns : null),
            $extra,
            ['AUDIT_RUN' => $run->id],
        );

        $console->section('orchestrator');

        $timeout = max(60, $this->config->int('agent.orchestrator.timeout'));
        $startedAt = microtime(true);
        $process = Process::path($request->workingDirectory)
            ->timeout($timeout + 60)
            ->env([...ChildEnvironment::forApp($plan->project)->overrides(), ...$driver->environment($request), ...$request->environment])
            ->input($request->prompt)
            ->start($driver->orchestratorCommand($request));

        while ($process->running() && microtime(true) - $startedAt <= $timeout) {
            $this->progress($context, $orchestrated);
            usleep($this->pollMicroseconds);
        }

        $result = $this->finish($process, $driver, $run->logsDir(), $timeout, $startedAt);

        while ($orchestrated->lock->current() !== null && microtime(true) - $startedAt <= $timeout) {
            $this->progress($context, $orchestrated);
            usleep($this->pollMicroseconds);
        }

        $this->progress($context, $orchestrated);
        $this->answer($context, $orchestrated, $result);

        return $orchestrated;
    }

    private function finish(InvokedProcess $process, OrchestratingDriver $driver, string $logsDir, int $timeout, float $startedAt): AgentResult
    {
        $logs = new TaskLog($logsDir);

        if ($process->running()) {
            $process->signal(15);
            $logs->write(OrchestrationAgent::KEY, $process->output(), $process->errorOutput());

            return AgentResult::failed(AgentExit::Timeout, sprintf('Orchestrator timed out after %d seconds.', $timeout), (int) ((microtime(true) - $startedAt) * 1000));
        }

        $finished = $process->wait();
        $logs->write(OrchestrationAgent::KEY, $finished->output(), $finished->errorOutput());

        return $driver->parse($finished->output(), (int) $finished->exitCode());
    }

    private function progress(RunContext $context, OrchestratedRun $run): void
    {
        $console = $context->console;

        foreach ($run->preflight()?->steps->all() ?? [] as $result) {
            if (! Arr::has($this->shown, 'step:'.$result->step->value)) {
                $this->shown['step:'.$result->step->value] = true;
                $console->stepFinished($result);
            }
        }

        foreach ($run->store->keys() as $key) {
            $record = $run->store->record($key);
            $marker = 'job:'.$key.':'.Read::float($record ?? [], 'finished_at');

            if ($record === null || Arr::has($this->shown, $marker)) {
                continue;
            }

            $this->shown[$marker] = true;
            $completed = Read::string($record, 'status') === 'completed';
            $detail = $completed
                ? $console->messages->get('agents.task_finished', [
                    'findings' => count(Read::arrays($record, 'findings')),
                    'duration' => TaskBoard::duration(Read::float($record, 'finished_at') - Read::float($record, 'started_at')),
                ])
                : $console->messages->get('agents.failed', ['reason' => Read::string($record, 'reason')]);

            $console->live->log(sprintf('  <fg=%s>%s</> %s · %s', $completed ? 'green' : 'red', $completed ? '✓' : '✗', $key, $detail));
        }
    }

    private function answer(RunContext $context, OrchestratedRun $run, AgentResult $result): void
    {
        $console = $context->console;
        $console->live->release();

        if ($result->answer !== '') {
            file_put_contents(OrchestratedRun::directory($run->run).'/answer.md', $result->answer."\n");

            foreach (explode("\n", trim($result->answer)) as $line) {
                $console->line('  <fg=gray>'.$line.'</>');
            }
        }

        if (! $result->succeeded()) {
            $console->line('  <fg=red>✗</> '.$console->messages->get('orchestrator.failed', ['reason' => $result->error ?? $result->exit->value]));
        }
    }
}
