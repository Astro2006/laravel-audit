<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Console\AgentProgress;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Orchestration\RunContext;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContextBuilder;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Run\AuditTasks;
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
        $tasks = $this->tasks($context, $summary, $validator);

        $console->section('agents');
        $jobs = $tasks->jobs();

        foreach ($tasks->skipped() as $key => $reason) {
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

        $raw = $runner->run($jobs, $progress->started(...), $progress->finished(...), $progress->tick(...), $progress->repairing(...));

        $outcomes = $tasks->outcomes($raw);
        $context->manifest->overview = $tasks->resultsSummary($raw);

        $console->live->release();
        $console->newLine();

        foreach ($outcomes as $outcome) {
            $console->outcome($outcome);
        }

        return $outcomes;
    }

    private function tasks(RunContext $context, PreflightSummary $summary, ReportValidator $validator): AuditTasks
    {
        $run = $context->run;
        $project = $context->plan->project;
        $driver = $context->plan->driver;

        // Agents may read the run folder even when it lives outside the project.
        $extra = str_starts_with($run->dir(), rtrim($project->basePath, '/').'/') ? [] : [$run->outputRoot];
        $driverConfig = $driver->writeConfig($run->logsDir(), $extra);
        $snapshots = SnapshotWriter::standard($this->config->int('snapshot_rows'))->write($summary, $project->relative($run->preflightDir()));
        $prompt = (new PromptContextBuilder)->build($summary, $run, $project, $this->config, $context->console->messages->locale, $project->boostMcpServer() !== null, $driver->toolsNote(), $snapshots);

        return new AuditTasks(
            $context->plan->selection->audits,
            $summary,
            $prompt,
            PromptTemplates::publishedPath(),
            $validator,
            $run,
            $project->basePath,
            $driverConfig,
            $extra,
            max(1, $this->config->int('max_findings_per_task')),
            $this->config->string('summary') === AuditTasks::SUMMARY_AGENT ? AuditTasks::SUMMARY_AGENT : AuditTasks::SUMMARY_GENERATED,
            $this->config->bool('agent.verify.enabled'),
            Severity::tryFrom($this->config->string('agent.verify.min_severity')) ?? Severity::Medium,
        );
    }
}
