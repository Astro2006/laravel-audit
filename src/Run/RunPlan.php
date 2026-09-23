<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Support\ProjectInspector;

final readonly class RunPlan
{
    public function __construct(
        public ProjectInspector $project,
        public RunSelection $selection,
        public AgentDriver $driver,
        public int $concurrency,
        public DatabaseGuard $guard,
    ) {}
}
