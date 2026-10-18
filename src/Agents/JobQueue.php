<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Illuminate\Support\Arr;

final class JobQueue
{
    /** @var list<AgentJob> */
    private array $pending;

    /** @var list<string> */
    private readonly array $keys;

    /** @param  list<AgentJob>  $jobs  in the order they should start */
    public function __construct(array $jobs)
    {
        $this->keys = array_map(fn (AgentJob $job): string => $job->key, $jobs);
        $this->pending = self::criticalFirst($jobs);
    }

    public function isEmpty(): bool
    {
        return $this->pending === [];
    }

    /**
     * The first pending job whose dependencies have finished, taken off the
     * queue; null when no pending job is ready.
     *
     * @param  array<string, TaskOutcome>  $finished
     */
    public function take(array $finished): ?AgentJob
    {
        foreach ($this->pending as $index => $job) {
            if ($this->ready($job, $finished)) {
                Arr::forget($this->pending, $index);
                $this->pending = array_values($this->pending);

                return $job;
            }
        }

        return null;
    }

    /**
     * Every pending job, taken off the queue.
     *
     * @return list<AgentJob>
     */
    public function drain(): array
    {
        $jobs = $this->pending;
        $this->pending = [];

        return $jobs;
    }

    /**
     * @param  array<string, TaskOutcome>  $finished
     * @return bool whether every dependency inside this run has finished; dependencies outside the run are ignored
     */
    private function ready(AgentJob $job, array $finished): bool
    {
        foreach ($job->dependsOn as $dependency) {
            if (in_array($dependency, $this->keys, true) && ! Arr::has($finished, $dependency)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The jobs ordered by the length of the longest chain that waits on them,
     * longest first; jobs with equal chains keep their order.
     *
     * @param  list<AgentJob>  $jobs
     * @return list<AgentJob>
     */
    private static function criticalFirst(array $jobs): array
    {
        $dependents = [];

        foreach ($jobs as $job) {
            foreach ($job->dependsOn as $dependency) {
                $dependents[$dependency][] = $job->key;
            }
        }

        $chains = [];
        $chain = function (string $key, array $seen = []) use (&$chain, &$chains, $dependents): int {
            if (Arr::has($chains, $key)) {
                return Arr::get($chains, $key);
            }

            $longest = 0;

            foreach (Arr::get($dependents, $key, []) as $dependent) {
                $longest = in_array($dependent, $seen, true) ? $longest : max($longest, $chain($dependent, [...$seen, $key]));
            }

            return $chains[$key] = $longest + 1;
        };

        $order = array_keys($jobs);
        usort($order, fn (int $a, int $b): int => [$chain(Arr::get($jobs, $b)->key), $a] <=> [$chain(Arr::get($jobs, $a)->key), $b]);

        return array_map(fn (int $index): AgentJob => Arr::get($jobs, $index), $order);
    }
}
