<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\AgentMode;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Orchestration\RunPlan;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ProjectInspector;

final class PlanRun
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @throws InvalidSelection for unknown audits, steps, drivers or modes */
    public function handle(AuditOptions $options): RunPlan
    {
        $project = ProjectInspector::fromConfig($this->config);
        $mode = AgentMode::resolve($options->mode ?? $this->config->string('agent.mode'));

        return new RunPlan(
            project: $project,
            selection: RunSelection::resolve($this->config->array('audits'), $options->audits, $options->without),
            driver: (new DriverRegistry($this->config, $project))->resolve($options->agent, $options->model),
            mode: $mode,
            concurrency: $mode->concurrency($this->config->int('agent.concurrency')),
            guard: DatabaseGuard::forApp($project),
        );
    }
}
