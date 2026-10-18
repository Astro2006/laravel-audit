<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Console\Output\AuditConsole;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Run\Phases\FinalizeRun;
use Astro2006\LaravelAudit\Run\Phases\Preflight;
use Astro2006\LaravelAudit\Run\Phases\RunAgents;
use Astro2006\LaravelAudit\Run\Phases\StartRun;
use Astro2006\LaravelAudit\Run\RunContext;

final class Finish
{
    public function __construct(
        private readonly RunAgents $runAgents,
        private readonly FinalizeRun $finalizeRun,
    ) {}

    /** @return array<string, mixed> */
    public function handle(OpenedRun $run, AuditConsole $console): array
    {
        if ($run->lock->current() !== null) {
            return ['error' => 'busy', 'hint' => 'Run `wait` until the worker is idle, then `finish`.'];
        }

        $summary = $run->preflight();

        if ($summary === null) {
            return ['error' => 'There is no preflight yet, so there is nothing to report.', 'hint' => 'Run `preflight <step…>` and `wait` first.'];
        }

        $plan = $run->plan;
        $manifest = StartRun::manifest($run->run, $plan);
        $manifest->steps = $summary->steps;
        $manifest->preflightComplete = ! $summary->steps->hasErrors();
        $manifest->gate = $summary->gate;
        $manifest->browser = Preflight::browserLabel($summary);

        if ($summary->gate === GateDecision::Aborted) {
            $manifest->close(RunStatus::Aborted);

            return ['status' => RunStatus::Aborted->value, 'report' => $plan->project->relative($run->run->manifestPath())];
        }

        $context = new RunContext($plan, $run->run, $manifest, $run->state->options(), $console);
        $outcomes = $this->runAgents->handle($context, $summary, $run->store->taskKeys());
        $exit = $this->finalizeRun->handle($context, $summary, $outcomes);

        return [
            'status' => $exit === AuditExit::Ok ? RunStatus::Completed->value : RunStatus::Failed->value,
            'report' => $plan->project->relative($run->run->manifestPath()),
            'audits' => array_map(fn (AuditOutcome $outcome): array => [
                'audit' => $outcome->audit->value,
                'status' => $outcome->status->value,
                'report' => $plan->project->relative($run->run->dir().'/'.$outcome->audit->value.'.md'),
                'findings' => array_count_values(array_map(fn (Finding $finding): string => $finding->severity->value, $outcome->findings)),
            ], $outcomes),
        ];
    }
}
