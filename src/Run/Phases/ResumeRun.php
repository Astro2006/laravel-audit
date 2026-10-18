<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Console\Output\AuditConsole;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\AuditOptions;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Astro2006\LaravelAudit\Run\RunContext;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\PackageVersion;
use Astro2006\LaravelAudit\Support\Read;
use Astro2006\LaravelAudit\Tool\TaskStore;

final class ResumeRun
{
    public function __construct(
        private readonly AuditConfig $config,
        private readonly PlanRun $planRun,
        private readonly CheckRequirements $checkRequirements,
        private readonly RunAgents $runAgents,
        private readonly FinalizeRun $finalizeRun,
    ) {}

    /**
     * Continues an earlier run with the same audits: tasks with a completed
     * outcome keep it, every other job runs, then the reports are written.
     *
     * @return AuditExit the exit code of the resumed run
     */
    public function handle(AuditOptions $options, AuditConsole $console): AuditExit
    {
        $messages = $console->messages;
        $outputRoot = rtrim($this->config->string('output_path'), '/');
        $found = (new PreflightReuse)->find($outputRoot, $options->resumeRunId, '');
        $run = $found === null ? null : AuditRun::open($outputRoot, $found->runId);

        if ($run === null || ! is_file($run->preflightDir().'/summary.json')) {
            $console->error($messages->get('resume.not_found', ['path' => $outputRoot]));

            return AuditExit::HarnessError;
        }

        $recorded = Json::readFile($run->dir().'/run.json');

        try {
            $plan = $this->planRun->handle(new AuditOptions(
                audits: Read::strings($recorded, 'audits'),
                without: Read::strings($recorded, 'without'),
                force: $options->force,
                agent: $options->agent,
                model: $options->model,
                concurrency: $options->concurrency,
            ));
        } catch (InvalidSelection $exception) {
            $console->error($exception->getMessage());

            return AuditExit::HarnessError;
        }

        $checks = $this->checkRequirements->handle($plan, $messages, true);
        $console->header(PackageVersion::get(), $plan->driver, $messages->get($plan->concurrency === 1 ? 'modes.single' : 'modes.parallel', ['count' => $plan->concurrency]), $plan->selection);
        $console->checks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return AuditExit::HarnessError;
        }

        $summary = PreflightSummary::fromDirectory($run->preflightDir());
        $manifest = StartRun::manifest($run, $plan);
        $manifest->steps = $summary->steps;
        $manifest->preflightComplete = ! $summary->steps->hasErrors();
        $manifest->gate = $summary->gate === GateDecision::Aborted && $options->force ? GateDecision::Forced : $summary->gate;
        $manifest->browser = Preflight::browserLabel($summary);

        if (! $manifest->gate->allowsAgents()) {
            $manifest->close(RunStatus::Aborted);
            $console->closing('<fg=yellow>'.$messages->get('gate.aborted', ['path' => $plan->project->relative($run->preflightDir())]).'</>');

            return AuditExit::GateAborted;
        }

        $store = new TaskStore(TaskStore::directoryFor($run));
        $kept = array_filter($store->taskKeys(), fn (string $key): bool => Read::string($store->record($key) ?? [], 'status') === AuditStatus::Completed->value);
        $console->line('  '.$messages->get('resume.using', ['run' => $run->id, 'done' => count($kept)]));

        $context = new RunContext($plan, $run, $manifest, new AuditOptions(force: $options->force, resume: true), $console);
        $outcomes = $this->runAgents->handle($context, $summary);

        return $this->finalizeRun->handle($context, $summary, $outcomes);
    }
}
