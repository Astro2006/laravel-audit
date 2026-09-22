<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Astro2006\LaravelAudit\Orchestration\RunContext;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Support\AuditConfig;

/**
 * Shows failing tests and browser tests of the finished preflight and
 * decides whether the agents start. --force continues without asking.
 */
final class ApplyTestGate
{
    public function __construct(private readonly AuditConfig $config) {}

    public function handle(RunContext $context, StepResults $results): GateDecision
    {
        $console = $context->console;
        $failing = TestGate::failures($results) > 0;

        if ($failing) {
            $console->gateFailures($results);
        }

        // Without agents there is nothing to protect. --reuse-preflight asks
        // again before agents start.
        if ($context->options->preflightOnly && $failing) {
            return GateDecision::Continued;
        }

        return (new TestGate)->decide(
            $results,
            GateMode::tryFrom($this->config->string('preflight.tests.gate')) ?? GateMode::Ask,
            $context->options->force,
            $console->interactive,
            $console->confirmGate(...),
        );
    }
}
