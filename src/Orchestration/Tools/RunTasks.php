<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Illuminate\Support\Arr;

final readonly class RunTasks
{
    public const array BASE_STEPS = [PreflightStep::About, PreflightStep::Stack, PreflightStep::LinesOfCode];

    /**
     * @param  array<string, AuditTask>  $tasks  key => task
     * @param  array<string, Audit>  $audits  task key => its audit
     */
    private function __construct(
        public array $tasks,
        public array $audits,
    ) {}

    public static function for(OrchestratedRun $run, AuditConfig $config): self
    {
        $tasks = [];
        $audits = [];

        foreach ($run->plan->selection->audits as $type) {
            $audit = $type->audit(PromptTemplates::publishedPath());

            foreach ($audit->tasks($config->strings('custom_checks.'.$type->value) !== []) as $task) {
                if (! $task->isSummary()) {
                    $tasks[$task->key()] = $task;
                    $audits[$task->key()] = $audit;
                }
            }
        }

        return new self($tasks, $audits);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->tasks, $key);
    }

    /**
     * The preflight steps whose data the task gets in its prompt.
     *
     * @return list<PreflightStep>
     */
    public function steps(AuditTask $task): array
    {
        $steps = self::BASE_STEPS;

        foreach ($task->snapshots as $snapshot) {
            array_push($steps, ...$snapshot->steps());
        }

        return self::ordered($steps);
    }

    /**
     * @param  list<PreflightStep>  $steps
     * @return list<PreflightStep> without duplicates, in execution order
     */
    public static function ordered(array $steps): array
    {
        return array_values(array_filter(PreflightStep::cases(), fn (PreflightStep $step): bool => in_array($step, $steps, true)));
    }

    /**
     * @param  list<PreflightStep|SnapshotType>  $steps
     * @return list<string>
     */
    public static function values(array $steps): array
    {
        return array_map(fn (PreflightStep|SnapshotType $step): string => $step->value, $steps);
    }
}
