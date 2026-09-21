<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Support\Messages;

/**
 * What the agent phase is doing: every task of the run, which ones are
 * running (and for how long), which wait and which are done.
 */
final class TaskBoard
{
    /** @var list<AgentJob> */
    private array $jobs;

    /** @var array<string, float> key => microtime of the start, in start order */
    private array $running = [];

    /** @var array<string, true> */
    private array $repairing = [];

    /** @var array<string, TaskOutcome> */
    private array $finished = [];

    /** @param list<AgentJob> $jobs */
    public function __construct(array $jobs, private readonly Messages $messages, private readonly float $startedAt)
    {
        $this->jobs = $jobs;
    }

    public function started(AgentJob $job, float $at): void
    {
        $this->running[$job->key] = $at;
    }

    public function repairing(AgentJob $job): void
    {
        $this->repairing[$job->key] = true;
    }

    public function finished(TaskOutcome $outcome): void
    {
        unset($this->running[$outcome->key], $this->repairing[$outcome->key]);
        $this->finished[$outcome->key] = $outcome;
    }

    /**
     * One line per audit with all of its tasks, shown before the first
     * task starts.
     *
     * @return list<string>
     */
    public function plan(): array
    {
        $tasks = [];

        foreach ($this->jobs as $job) {
            $tasks[$job->audit->value][] = substr($job->key, strlen($job->audit->value) + 1);
        }

        $lines = [];

        foreach ($tasks as $audit => $names) {
            $lines[] = '  <fg=gray>'.$this->messages->get('board.plan', ['audit' => $audit, 'count' => count($names), 'tasks' => implode(', ', $names)]).'</>';
        }

        return $lines;
    }

    /**
     * The live area: counts, the running tasks with their time, and the
     * tasks that have not started yet.
     *
     * @return list<string>
     */
    public function live(float $now, int $width): array
    {
        $waiting = array_values(array_filter(
            array_map(fn (AgentJob $job): string => $job->key, $this->jobs),
            fn (string $key): bool => ! isset($this->running[$key]) && ! isset($this->finished[$key]),
        ));

        if ($this->running === [] && $waiting === []) {
            return [];
        }

        $failed = count(array_filter($this->finished, fn (TaskOutcome $outcome): bool => ! $outcome->completed()));

        $lines = ['  <fg=blue>◷</> '.$this->messages->get('board.status', [
            'running' => count($this->running),
            'waiting' => count($waiting),
            'done' => count($this->finished) - $failed,
            'failed' => $failed,
            'total' => count($this->jobs),
            'elapsed' => self::duration($now - $this->startedAt),
        ])];

        foreach ($this->running as $key => $startedAt) {
            $detail = self::duration($now - $startedAt).(isset($this->repairing[$key]) ? ' · '.$this->messages->get('board.repair') : '');
            $lines[] = Columns::detail('<fg=yellow>●</> '.$key, '<fg=yellow>'.$detail.'</>', $width);
        }

        if ($waiting !== []) {
            $lines[] = '  <fg=gray>'.Columns::fit('◌ '.$this->messages->get('board.waiting', ['tasks' => implode(', ', $waiting)]), $width - 2).'</>';
        }

        return $lines;
    }

    public static function duration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
    }
}
