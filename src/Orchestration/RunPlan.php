<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentMode;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * What a run will do, resolved from the options and the config before
 * anything is written.
 */
final readonly class RunPlan
{
    public function __construct(
        public ProjectInspector $project,
        public RunSelection $selection,
        public AgentDriver $driver,
        public AgentMode $mode,
        public int $concurrency,
        public DatabaseGuard $guard,
    ) {}
}
