<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Orchestration\Phases\CheckRequirements;
use Astro2006\LaravelAudit\Orchestration\Phases\FinalizeRun;
use Astro2006\LaravelAudit\Orchestration\Phases\PlanRun;
use Astro2006\LaravelAudit\Orchestration\Phases\Preflight;
use Astro2006\LaravelAudit\Orchestration\Phases\RunAgents;
use Astro2006\LaravelAudit\Orchestration\Phases\StartRun;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Support\PackageVersion;

final class AuditOrchestrator
{
    public function __construct(
        private readonly PlanRun $planRun,
        private readonly CheckRequirements $checkRequirements,
        private readonly StartRun $startRun,
        private readonly Preflight $preflight,
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

        $checks = $this->checkRequirements->handle($plan, $console->messages, ! $options->preflightOnly);
        $console->header(PackageVersion::get(), $plan->driver, $console->messages->get('modes.'.$plan->mode->value, ['count' => $plan->concurrency]), $plan->selection);
        $console->checks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return AuditExit::HarnessError;
        }

        $context = $this->startRun->handle($plan, $options, $console);
        $summary = $this->preflight->handle($context);

        if ($summary === null) {
            $context->manifest->close(RunStatus::Failed);

            return AuditExit::HarnessError;
        }

        if (! $summary->gate->allowsAgents()) {
            $context->manifest->close(RunStatus::Aborted);
            $console->closing('<fg=yellow>'.$console->messages->get('gate.aborted', ['path' => $plan->project->relative($context->run->preflightDir())]).'</>');

            return AuditExit::GateAborted;
        }

        if ($options->preflightOnly) {
            $context->manifest->close(RunStatus::PreflightOnly);
            $console->closing($console->messages->get('summary.preflight_only', ['path' => $plan->project->relative($context->run->manifestPath())]));

            return AuditExit::Ok;
        }

        $outcomes = $this->runAgents->handle($context, $summary);

        return $this->finalizeRun->handle($context, $summary, $outcomes);
    }
}
