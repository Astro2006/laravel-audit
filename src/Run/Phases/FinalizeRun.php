<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Run\RiskPoints;
use Astro2006\LaravelAudit\Run\RunContext;
use Astro2006\LaravelAudit\Run\RunFinalizer;
use Astro2006\LaravelAudit\Support\AuditConfig;

final class FinalizeRun
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @param list<AuditOutcome> $outcomes */
    public function handle(RunContext $context, PreflightSummary $summary, array $outcomes): AuditExit
    {
        $riskPoints = new RiskPoints($this->config->array('risk_points.weights'), $this->config->array('risk_points.confidence'));

        (new RunFinalizer($riskPoints))->finalize($context->manifest, $summary, $outcomes, $context->plan->driver->name());
        $context->console->summary($context->manifest, $outcomes, $context->plan->project->relative($context->manifest->resultPath()));

        foreach ($outcomes as $outcome) {
            if (! $outcome->completed()) {
                return AuditExit::HarnessError;
            }
        }

        return AuditExit::Ok;
    }
}
