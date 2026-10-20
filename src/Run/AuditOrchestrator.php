<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Console\Output\AuditConsole;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\Phases\CheckModel;
use Astro2006\LaravelAudit\Run\Phases\CheckRequirements;
use Astro2006\LaravelAudit\Run\Phases\FinalizeRun;
use Astro2006\LaravelAudit\Run\Phases\PauseRun;
use Astro2006\LaravelAudit\Run\Phases\PlanRun;
use Astro2006\LaravelAudit\Run\Phases\Preflight;
use Astro2006\LaravelAudit\Run\Phases\ResumeRun;
use Astro2006\LaravelAudit\Run\Phases\RunAgents;
use Astro2006\LaravelAudit\Run\Phases\SelectSkills;
use Astro2006\LaravelAudit\Run\Phases\StartRun;
use Astro2006\LaravelAudit\Support\PackageVersion;

final class AuditOrchestrator
{
    public function __construct(
        private readonly PlanRun $planRun,
        private readonly CheckRequirements $checkRequirements,
        private readonly CheckModel $checkModel,
        private readonly StartRun $startRun,
        private readonly Preflight $preflight,
        private readonly SelectSkills $selectSkills,
        private readonly RunAgents $runAgents,
        private readonly FinalizeRun $finalizeRun,
        private readonly ResumeRun $resumeRun,
        private readonly PauseRun $pauseRun,
    ) {}

    public function run(AuditOptions $options, AuditConsole $console): AuditExit
    {
        if ($options->resume) {
            return $this->resumeRun->handle($options, $console);
        }

        try {
            $plan = $this->planRun->handle($options);
        } catch (InvalidSelection $exception) {
            $console->error($exception->getMessage());

            return AuditExit::HarnessError;
        }

        $checks = $this->checkRequirements->handle($plan, $console->messages, ! $options->preflightOnly);
        $console->header(PackageVersion::get(), $plan->driver, $console->messages->get($plan->concurrency === 1 ? 'modes.single' : 'modes.parallel', ['count' => $plan->concurrency]), $plan->selection);
        $console->checks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return AuditExit::HarnessError;
        }

        $driver = $options->preflightOnly ? $plan->driver : $this->checkModel->handle($plan->driver, $plan->project, $console);

        if ($driver === null) {
            return AuditExit::HarnessError;
        }

        $plan = $plan->withDriver($driver);
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

        $this->selectSkills->handle($context);
        $outcomes = $this->runAgents->handle($context, $summary);

        if ($context->manifest->pause !== null) {
            return $this->pauseRun->handle($context)
                ? $this->resumeRun->handle(new AuditOptions(force: $options->force, resume: true, resumeRunId: $context->run->id), $console)
                : AuditExit::Paused;
        }

        return $this->finalizeRun->handle($context, $summary, $outcomes);
    }
}
