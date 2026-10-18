<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Illuminate\Support\Arr;

final class FindingOrder
{
    /**
     * The findings of an audit in report order: severity first, then
     * confidence, then the task order and the order within the task. Each
     * gets its final ID.
     *
     * @param  array<string, list<Finding>>  $byTask  task key => findings with task-local IDs, tasks in report order
     * @return list<array{task: string, from: string, finding: Finding}>
     */
    public static function sort(array $byTask, string $prefix): array
    {
        $entries = [];
        $taskIndex = 0;

        foreach ($byTask as $key => $findings) {
            foreach ($findings as $position => $finding) {
                $entries[] = ['task' => $key, 'finding' => $finding, 'rank' => [-$finding->severity->rank(), -$finding->confidence->rank(), $taskIndex, $position]];
            }

            $taskIndex++;
        }

        usort($entries, fn (array $a, array $b): int => Arr::get($a, 'rank') <=> Arr::get($b, 'rank'));

        $ordered = [];
        $number = 0;

        foreach ($entries as $entry) {
            $finding = Arr::get($entry, 'finding');
            $ordered[] = ['task' => Arr::get($entry, 'task'), 'from' => $finding->id, 'finding' => $finding->withId(sprintf('%s-%03d', $prefix, ++$number))];
        }

        return $ordered;
    }
}
