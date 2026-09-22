<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Orchestration\WorkerLock;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\PreflightRunner;
use Astro2006\LaravelAudit\Preflight\SummaryBuilder;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Run\RunJobs;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\Messages;
use Illuminate\Support\Arr;

final class Work
{
    public function __construct(private readonly AuditConfig $config) {}

    public function handle(OrchestratedRun $run): void
    {
        $lock = $run->lock->current();

        if ($lock === null) {
            return;
        }

        $run->lock->claim((int) getmypid());

        try {
            Arr::get($lock, 'kind') === WorkerLock::PREFLIGHT
                ? $this->preflight($run, Arr::get($lock, 'items'))
                : $this->tasks($run, Arr::get($lock, 'items'));
        } finally {
            $run->lock->release();
        }
    }

    /** @param  list<string>  $items */
    private function preflight(OrchestratedRun $run, array $items): void
    {
        $plan = $run->plan;
        $steps = array_values(array_filter(array_map(fn (string $item): ?PreflightStep => PreflightStep::tryFrom($item), $items)));
        $context = new PreflightContext($plan->project, $run->run->preflightDir(), $run->run->logsDir(), $this->config, $plan->selection->onlySteps($steps), ChildEnvironment::forApp($plan->project));
        $summary = new SummaryBuilder;

        foreach ($run->preflight()?->steps->all() ?? [] as $result) {
            $context->results->put($result);
        }

        PreflightRunner::standard($plan->guard, (string) config('app.locale', 'en'))->run(
            $context,
            function () use ($summary, $context, $run): void {
                $summary->write($context, $run->run->id, GateDecision::NotRun);
            },
        );

        $gate = (new TestGate)->decide(
            $context->results,
            GateMode::tryFrom($this->config->string('preflight.tests.gate')) ?? GateMode::Ask,
            $run->state->force,
            false,
            fn (): bool => false,
        );

        $summary->write($context, $run->run->id, $gate);
    }

    /** @param  list<string>  $keys */
    private function tasks(OrchestratedRun $run, array $keys): void
    {
        $preflight = $run->preflight();

        if ($preflight === null) {
            return;
        }

        $plan = $run->plan;
        $validator = ReportValidator::forProject($plan->project->basePath);
        $jobs = (new RunJobs($this->config))->plan($plan, $run->run, $preflight, Messages::fromConfig()->locale, $keys, withHarnessJobs: false);
        $keysInBatch = array_map(fn (AgentJob $job): string => $job->key, $jobs->jobs);

        $runner = new AgentRunner(
            $plan->driver,
            $validator,
            $run->run->logsDir(),
            $plan->concurrency,
            $this->config->int('agent.timeout'),
            environment: ChildEnvironment::forApp($plan->project),
        );

        $outcomes = $runner->run(
            $jobs->jobs,
            onFinished: $run->store->save(...),
            earlier: $run->store->load($jobs, $validator, except: $keysInBatch),
        );

        foreach ($outcomes as $outcome) {
            $run->store->save($outcome);
        }
    }
}
