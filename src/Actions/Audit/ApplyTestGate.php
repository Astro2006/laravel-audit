<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Audit;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Support\HarnessFactory;

/**
 * Shows failing tests and browser tests of the finished preflight and
 * decides whether the agents start. --force continues without asking.
 */
final class ApplyTestGate
{
    public function __construct(private readonly HarnessFactory $factory) {}

    public function handle(StepResults $results, AuditOptions $options, AuditConsole $console): GateDecision
    {
        $failing = TestGate::failures($results) > 0;

        if ($failing) {
            $console->gateFailures($results);
        }

        // Without agents there is nothing to protect. --reuse-preflight asks
        // again before agents start.
        if ($options->preflightOnly && $failing) {
            return GateDecision::Continued;
        }

        return (new TestGate)->decide(
            $results,
            GateMode::tryFrom((string) $this->factory->config('preflight.tests.gate', 'ask')) ?? GateMode::Ask,
            $options->force,
            $console->interactive,
            $console->confirmGate(...),
        );
    }
}
