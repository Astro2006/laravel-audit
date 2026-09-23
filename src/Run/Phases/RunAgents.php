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
use Astro2006\LaravelAudit\Run\JobPlan;
use Astro2006\LaravelAudit\Run\OutcomeAssembler;
use Astro2006\LaravelAudit\Run\RunContext;
use Astro2006\LaravelAudit\Run\RunJobs;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Closure;
use Illuminate\Support\Arr;

final class RunAgents
{
    public function __construct(private readonly AuditConfig $config) {}

    /**
     * @param  list<string>|null  $only  task keys an orchestrator started; the others count as skipped
     * @param  (Closure(JobPlan): array<string, TaskOutcome>)|null  $earlier  outcomes of the jobs that already ran, read once the plan exists
     * @return list<AuditOutcome>
     */
    public function handle(RunContext $context, PreflightSummary $summary, ?array $only = null, ?Closure $earlier = null): array
    {
        $console = $context->console;
        $project = $context->plan->project;
        $validator = ReportValidator::forProject($project->basePath);
        $plan = (new RunJobs($this->config))->plan($context->plan, $context->run, $summary, $console->messages->locale, $only);
        $done = $earlier === null ? [] : $earlier($plan);
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
        );

        $progress = new AgentProgress($jobs, $console->live, $console->messages);
        $progress->begin();

        $raw = [...array_values($done), ...$runner->run($jobs, $progress->started(...), $progress->finished(...), $progress->tick(...), $progress->repairing(...), $done)];

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
