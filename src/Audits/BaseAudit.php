<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;

abstract class BaseAudit implements Audit
{
    final public function tasks(bool $withCustom = false): array
    {
        $tasks = $this->taskList();

        if ($withCustom) {
            $tasks[] = $this->task(AuditTask::CUSTOM, 'Custom checks', ['custom']);
        }

        $tasks[] = $this->task(
            AuditTask::SUMMARY,
            'Summary',
            [],
            sections: $this->summarySections(),
            dependsOn: array_map(fn (AuditTask $task): string => $task->name, $tasks),
        );

        return $tasks;
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        return [];
    }

    /**
     * The audit's own tasks, without custom and summary.
     *
     * @return list<AuditTask>
     */
    abstract protected function taskList(): array;

    /**
     * Sections the summary task writes from the merged findings.
     *
     * @return list<string>
     */
    protected function summarySections(): array
    {
        return [];
    }

    /**
     * @param  list<string>  $checks
     * @param  list<string>  $sections
     * @param  list<string>  $dependsOn
     */
    protected function task(string $name, string $title, array $checks, array $sections = [], array $dependsOn = []): AuditTask
    {
        return new AuditTask($this->type(), $name, $title, $checks, $sections, $dependsOn);
    }

    /**
     * Checks every audit shares.
     *
     * @return array<string, string>
     */
    protected function commonChecks(): array
    {
        return [
            'custom' => 'A project-specific rule from the custom checks list.',
            'other' => 'Fits none of the checks above. Use sparingly.',
        ];
    }
}
