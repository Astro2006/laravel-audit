<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Illuminate\Support\Arr;

final class ChecksTable
{
    /**
     * One row per check of the audit: which task ran it and what came out.
     *
     * @param  list<AuditTask>  $tasks
     * @param  array<string, TaskOutcome>  $outcomes  by task key
     * @param  array<string, string>  $skipped  task key => why
     * @param  list<Finding>  $findings  with their final IDs
     * @param  array<string, string>  $findingTasks  final ID => task key
     */
    public static function render(array $tasks, array $outcomes, array $skipped, array $findings, array $findingTasks): string
    {
        $rows = ['| Task | Check | Result |', '|---|---|---|'];

        foreach ($tasks as $task) {
            if ($task->isSummary()) {
                continue;
            }

            $outcome = Arr::get($outcomes, $task->key());
            $checks = $task->checks;

            foreach ($findings as $finding) {
                if ($finding->check === 'other' && Arr::get($findingTasks, $finding->id) === $task->key() && ! in_array('other', $checks, true)) {
                    $checks[] = 'other';
                }
            }

            foreach ($checks as $check) {
                $ids = [];

                foreach ($findings as $finding) {
                    if ($finding->check === $check && Arr::get($findingTasks, $finding->id) === $task->key()) {
                        $ids[] = $finding->id;
                    }
                }

                $rows[] = sprintf('| %s | `%s` | %s |', $task->name, $check, self::result($task, $outcome, $skipped, $ids));
            }
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string, string>  $skipped
     * @param  list<string>  $ids
     */
    private static function result(AuditTask $task, ?TaskOutcome $outcome, array $skipped, array $ids): string
    {
        return match (true) {
            Arr::has($skipped, $task->key()) => 'skipped: '.rtrim((string) Arr::get($skipped, $task->key()), '.'),
            $outcome === null => 'not run',
            ! $outcome->completed() => 'not audited: '.rtrim((string) $outcome->reason, '.'),
            $ids === [] => 'clean',
            default => sprintf('%d %s (%s)', count($ids), count($ids) === 1 ? 'finding' : 'findings', implode(', ', $ids)),
        };
    }
}
