<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Console\Output\AgentProgress;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Run\OutcomeAssembler;
use Astro2006\LaravelAudit\Run\RunContext;
use Astro2006\LaravelAudit\Run\RunJobs;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Tool\Pause;
use Astro2006\LaravelAudit\Tool\TaskStore;
use Illuminate\Support\Arr;

final class RunAgents
{
    public function __construct(private readonly AuditConfig $config) {}

    /**
     * Runs the jobs of the run that have no stored outcome yet. Every
     * outcome is written to the run's task store as it arrives, so a run
     * that stops can be resumed.
     *
     * @param  list<string>|null  $only  task keys an orchestrator started; the others count as skipped
     * @return list<AuditOutcome>
     */
    public function handle(RunContext $context, PreflightSummary $summary, ?array $only = null): array
    {
        $console = $context->console;
        $project = $context->plan->project;
        $validator = ReportValidator::forProject($project->basePath);
        $plan = (new RunJobs($this->config))->plan($context->plan, $context->run, $summary, $console->messages->locale, $only);
        $store = new TaskStore(TaskStore::directoryFor($context->run));
        $done = $store->load($plan, $validator, completedOnly: $context->options->resume);
        $budget = $this->config->nullableFloat('agent.run_budget_usd');
        Pause::clear($context->run);
        $jobs = array_values(array_filter($plan->jobs, fn (AgentJob $job): bool => ! Arr::has($done, $job->key)));

        $console->section('agents');

        foreach ($plan->skipped as $key => $reason) {
            $console->line(sprintf('  <fg=gray>- %s · %s</>', $key, $console->messages->get('agents.skipped', ['reason' => $reason])));
        }

        $runner = new AgentRunner(
            $context->plan->driver,
            $validator,
            $context->run->logsDir(),
            $context->plan->concurrency,
            $this->config->int('agent.timeout'),
            environment: ChildEnvironment::forApp($project),
            budgetUsd: $budget === null ? null : $budget - $store->spendUsd(),
        );

        $progress = new AgentProgress($jobs, $console->live, $console->messages);
        $progress->begin();

        $new = $runner->run(
            $jobs,
            $progress->started(...),
            function (TaskOutcome $outcome) use ($store, $progress): void {
                $store->save($outcome);
                $progress->finished($outcome);
            },
            $progress->tick(...),
            $progress->repairing(...),
            $done,
        );

        foreach ($new as $outcome) {
            $store->save($outcome);
        }

        if ($runner->pause() !== null) {
            Pause::write($context->run, $runner->pause());
            $context->manifest->pause = $runner->pause();
        }

        $raw = [...array_values($done), ...$new];
        $assembler = new OutcomeAssembler($validator);
        $outcomes = $assembler->outcomes($plan, $raw);
        $context->manifest->overview = $assembler->overview($raw);

        $console->live->release();
        $console->newLine();

        foreach ($outcomes as $outcome) {
            $console->outcome($outcome);
        }

        return $outcomes;
    }
}
