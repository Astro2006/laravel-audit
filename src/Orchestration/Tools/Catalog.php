<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Illuminate\Support\Arr;

final class Catalog
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @return array<string, mixed> */
    public function handle(OrchestratedRun $run): array
    {
        $preflight = $run->preflight();
        $lock = $run->lock->current();
        $tasks = RunTasks::for($run, $this->config);
        $selection = $run->plan->selection;
        $steps = [];

        foreach (PreflightStep::cases() as $step) {
            $neededBy = [];

            foreach ($selection->audits as $audit) {
                if (in_array($step, Arr::get($selection->steps, $audit->value, []), true)) {
                    $neededBy[] = $audit->value;
                }
            }

            $steps[$step->value] = [
                'heavy' => $step->isHeavy(),
                'configured_for' => $neededBy,
                'status' => $preflight?->steps->get($step)?->status->value ?? 'not_run',
            ];
        }

        $catalog = [];

        foreach ($tasks->tasks as $key => $task) {
            $catalog[$key] = array_filter([
                'title' => $task->title,
                'checks' => $task->checks,
                'depends_on' => $task->dependencyKeys(),
                'reads_steps' => RunTasks::values($tasks->steps($task)),
                'skip_reason' => $preflight === null ? null : Arr::get($tasks->audits, $key)->skipReason($task, $preflight),
                'status' => Arr::get(Status::task($run, $key, $lock), 'status'),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return [
            'run' => $run->run->id,
            'audits' => $selection->auditValues(),
            'without' => $selection->withoutValues(),
            'preflight_steps' => $steps,
            'tasks' => $catalog,
        ];
    }
}
