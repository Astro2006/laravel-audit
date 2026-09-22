<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Console\AgentProgress;
use Astro2006\LaravelAudit\Orchestration\RunContext;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContextBuilder;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Run\JobPlan;
use Astro2006\LaravelAudit\Run\JobPlanner;
use Astro2006\LaravelAudit\Run\OutcomeAssembler;
use Astro2006\LaravelAudit\Run\TaskRequests;
use Astro2006\LaravelAudit\Run\TaskSettings;
use Astro2006\LaravelAudit\Snapshots\SnapshotWriter;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;

/**
 * Runs the agent tasks of the selected audits on top of the preflight and
 * turns their answers into one outcome per audit.
 */
final class RunAgents
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @return list<AuditOutcome> */
    public function handle(RunContext $context, PreflightSummary $summary): array
    {
        $console = $context->console;
        $project = $context->plan->project;
        $validator = ReportValidator::forProject($project->basePath);
        $plan = $this->plan($context, $summary);

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

        $progress = new AgentProgress($plan->jobs, $console->live, $console->messages);
        $progress->begin();

        $raw = $runner->run($plan->jobs, $progress->started(...), $progress->finished(...), $progress->tick(...), $progress->repairing(...));

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

    private function plan(RunContext $context, PreflightSummary $summary): JobPlan
    {
        $run = $context->run;
        $project = $context->plan->project;
        $driver = $context->plan->driver;
        $settings = TaskSettings::fromConfig($this->config);

        // Agents may read the run folder even when it lives outside the project.
        $extra = str_starts_with($run->dir(), rtrim($project->basePath, '/').'/') ? [] : [$run->outputRoot];
        $driverConfig = $driver->writeConfig($run->logsDir(), $extra);
        $snapshots = SnapshotWriter::standard($this->config->int('snapshot_rows'))->write($summary, $project->relative($run->preflightDir()));
        $prompt = (new PromptContextBuilder)->build($summary, $run, $project, $this->config, $context->console->messages->locale, $project->boostMcpServer() !== null, $driver->toolsNote(), $snapshots);
        $overridePath = PromptTemplates::publishedPath();
        $requests = new TaskRequests($prompt, $overridePath, $run, $project->basePath, $driverConfig, $extra, $settings->verifyMinSeverity);

        return (new JobPlanner($requests, $settings, $overridePath))->plan($context->plan->selection->audits, $summary, $prompt, $project);
    }
}
