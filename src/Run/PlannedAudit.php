<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Audits\Audit;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Illuminate\Support\Arr;

final readonly class PlannedAudit
{
    /**
     * @param  list<AuditTask>  $tasks  the summary task comes last
     * @param  list<RequiredFinding>  $required
     */
    public function __construct(
        public Audit $audit,
        public array $tasks,
        public array $required,
        public ?FindingGuard $guard,
    ) {}

    public function summaryTask(): AuditTask
    {
        return Arr::last($this->tasks);
    }

    /** @return list<RequiredFinding> the required findings one of the task's checks covers */
    public function requiredFor(AuditTask $task): array
    {
        return array_values(array_filter($this->required, fn (RequiredFinding $finding): bool => in_array($finding->check, $task->checks, true)));
    }
}
