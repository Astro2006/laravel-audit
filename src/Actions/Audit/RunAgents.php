<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Audit;

use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Console\AgentProgress;
use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContextBuilder;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\AuditTasks;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Runs the agent tasks of the selected audits on top of the preflight and
 * turns their answers into one outcome per audit.
 */
final class RunAgents
{
    public function __construct(private readonly HarnessFactory $factory) {}

    /** @return list<AuditOutcome> */
    public function handle(AgentDriver $driver, AuditRun $run, RunSelection $selection, ProjectInspector $project, PreflightSummary $summary, RunManifest $manifest, int $concurrency, AuditConsole $console): array
    {
        // Agents may read the run folder even when it lives outside the project.
        $extra = str_starts_with($run->dir(), rtrim($project->basePath, '/').'/') ? [] : [$run->outputRoot];
        $validator = $this->factory->validator($project);
        $tasks = $this->tasks($driver, $run, $selection, $project, $summary, $validator, $extra, $console);

        $console->section('agents');
        $jobs = $tasks->jobs();

        foreach ($tasks->skipped() as $key => $reason) {
            $console->line(sprintf('  <fg=gray>- %s · %s</>', $key, $console->messages->get('agents.skipped', ['reason' => $reason])));
        }

        $runner = new AgentRunner(
            $driver,
            $validator,
            $run->logsDir(),
            $concurrency,
            (int) $this->factory->config('agent.timeout', 900),
            environment: $this->factory->childEnvironment($project),
        );

        $progress = new AgentProgress($jobs, $console->live, $console->messages);
        $progress->begin();

        $raw = $runner->run($jobs, $progress->started(...), $progress->finished(...), $progress->tick(...), $progress->repairing(...));

        $outcomes = $tasks->outcomes($raw);
        $manifest->overview = $tasks->resultsSummary($raw);

        $console->live->release();
        $console->newLine();

        foreach ($outcomes as $outcome) {
            $console->outcome($outcome);
        }

        return $outcomes;
    }

    /** @param list<string> $extra */
    private function tasks(AgentDriver $driver, AuditRun $run, RunSelection $selection, ProjectInspector $project, PreflightSummary $summary, ReportValidator $validator, array $extra, AuditConsole $console): AuditTasks
    {
        $config = $driver->writeConfig($run->logsDir(), $extra);
        $snapshots = $this->factory->snapshotWriter()->write($summary, $project->relative($run->preflightDir()));
        $context = (new PromptContextBuilder)->build($summary, $run, $project, $this->factory->allConfig(), $console->messages->locale, $project->boostMcpServer() !== null, $driver->toolsNote(), $snapshots);

        return new AuditTasks(
            $selection->audits,
            $summary,
            $context,
            $this->factory->promptOverridePath(),
            $validator,
            $run,
            $project->basePath,
            $config,
            $extra,
            max(1, (int) $this->factory->config('max_findings_per_task', 5)),
            $this->factory->config('summary', AuditTasks::SUMMARY_GENERATED) === AuditTasks::SUMMARY_AGENT ? AuditTasks::SUMMARY_AGENT : AuditTasks::SUMMARY_GENERATED,
            (bool) $this->factory->config('agent.verify.enabled', true),
            Severity::tryFrom((string) $this->factory->config('agent.verify.min_severity', 'medium')) ?? Severity::Medium,
        );
    }
}
