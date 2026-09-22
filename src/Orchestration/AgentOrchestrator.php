<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Contracts\OrchestratingDriver;
use Astro2006\LaravelAudit\Contracts\Orchestrator;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Orchestration\Phases\CheckRequirements;
use Astro2006\LaravelAudit\Orchestration\Phases\FinalizeRun;
use Astro2006\LaravelAudit\Orchestration\Phases\PlanRun;
use Astro2006\LaravelAudit\Orchestration\Phases\Preflight;
use Astro2006\LaravelAudit\Orchestration\Phases\RunAgents;
use Astro2006\LaravelAudit\Orchestration\Phases\RunOrchestrator;
use Astro2006\LaravelAudit\Orchestration\Phases\StartRun;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\JobPlan;
use Astro2006\LaravelAudit\Support\PackageVersion;

final class AgentOrchestrator implements Orchestrator
{
    public function __construct(
        private readonly PlanRun $planRun,
        private readonly CheckRequirements $checkRequirements,
        private readonly StartRun $startRun,
        private readonly RunOrchestrator $runOrchestrator,
        private readonly RunAgents $runAgents,
        private readonly FinalizeRun $finalizeRun,
    ) {}

    public function run(AuditOptions $options, AuditConsole $console): AuditExit
    {
        try {
            $plan = $this->planRun->handle($options);
        } catch (InvalidSelection $exception) {
            $console->error($exception->getMessage());

            return AuditExit::HarnessError;
        }

        $driver = $plan->driver;

        if (! $driver instanceof OrchestratingDriver) {
            $console->error($console->messages->get('orchestrator.unsupported', ['agent' => $driver->displayName()]));

            return AuditExit::HarnessError;
        }

        $checks = $this->checkRequirements->handle($plan, $console->messages, true);
        $console->header(PackageVersion::get(), $driver, $console->messages->get('modes.'.$plan->mode->value, ['count' => $plan->concurrency]), $plan->selection);
        $console->checks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return AuditExit::HarnessError;
        }

        $context = $this->startRun->handle($plan, $options, $console);
        $orchestrated = $this->runOrchestrator->handle($context, $driver);
        $summary = $orchestrated->preflight();

        if ($summary === null) {
            $context->manifest->close(RunStatus::Failed);
            $console->error($console->messages->get('orchestrator.no_preflight'));

            return AuditExit::HarnessError;
        }

        $context->manifest->steps = $summary->steps;
        $context->manifest->preflightComplete = ! $summary->steps->hasErrors();
        $context->manifest->gate = $summary->gate;
        $context->manifest->browser = Preflight::browserLabel($summary);

        if ($summary->gate === GateDecision::Aborted) {
            $context->manifest->close(RunStatus::Aborted);
            $console->closing('<fg=yellow>'.$console->messages->get('gate.aborted', ['path' => $plan->project->relative($context->run->preflightDir())]).'</>');

            return AuditExit::GateAborted;
        }

        $validator = ReportValidator::forProject($plan->project->basePath);
        $outcomes = $this->runAgents->handle(
            $context,
            $summary,
            $orchestrated->store->taskKeys(),
            fn (JobPlan $jobs): array => $orchestrated->store->load($jobs, $validator),
        );

        return $this->finalizeRun->handle($context, $summary, $outcomes);
    }
}
