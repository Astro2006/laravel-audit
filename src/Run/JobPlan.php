<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AgentJob;

final readonly class JobPlan
{
    /**
     * @param  list<AgentJob>  $jobs
     * @param  array<string, PlannedAudit>  $audits  audit key => audit, in the order of the selection
     * @param  array<string, string>  $skipped  task key => why it was skipped
     */
    public function __construct(
        public array $jobs,
        public array $audits,
        public array $skipped,
    ) {}
}
