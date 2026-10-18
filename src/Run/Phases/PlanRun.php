<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Run\AuditOptions;
use Astro2006\LaravelAudit\Run\RunPlan;
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

        return new RunPlan(
            project: $project,
            selection: RunSelection::resolve($this->config->array('audits'), $options->audits, $options->without),
            driver: (new DriverRegistry($this->config, $project))->resolve($options->agent, $options->model),
            concurrency: self::concurrency($options->concurrency ?? (string) $this->config->int('agent.max_concurrency')),
            guard: DatabaseGuard::forApp($project),
        );
    }

    /** @throws InvalidSelection */
    private static function concurrency(string $value): int
    {
        if (preg_match('/^\s*\d+\s*$/', $value) !== 1 || (int) $value < 1) {
            throw InvalidSelection::invalidConcurrency($value);
        }

        return (int) $value;
    }
}
