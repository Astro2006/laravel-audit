<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Closure;

/**
 * Runs the agent jobs in parallel (up to the concurrency limit). A job
 * starts once the jobs it depends on have finished, whatever their status.
 * Of the jobs that are ready, those with the longest chain of jobs waiting
 * on them start first, so the run does not end on a long tail.
 * Every answer is validated, and each job gets one repair pass in the same
 * session.
 */
final class AgentRunner
{
    private const string REPAIR_MESSAGE = <<<'TEXT'
        Your report did not pass validation. Fix exactly these errors and return the complete corrected report as your final answer, starting with the `## %s` line. Do not audit again and do not change findings that are not affected.

        Errors:
        TEXT;

    private const string ANSWER_NOW_MESSAGE = 'You reached the turn limit. Stop investigating and write your complete report now from what you found, starting with the `## %s` line. Put what you could not check under `## Limitations`.';

    private readonly AgentProcesses $processes;

    public function __construct(
        private readonly AgentDriver $driver,
        private readonly ReportValidator $validator,
        string $logsDir,
        private readonly int $concurrency = 4,
        int $timeout = 1800,
        private readonly int $pollMicroseconds = 250_000,
        ChildEnvironment $environment = new ChildEnvironment,
    ) {
        $this->processes = new AgentProcesses($driver, $logsDir, $timeout, $environment);
    }

    /**
     * @param  list<AgentJob>  $jobs  in the order they should start
     * @param  (Closure(AgentJob): void)|null  $onStarted
     * @param  (Closure(TaskOutcome): void)|null  $onFinished
     * @param  (Closure(): void)|null  $onTick  called after every poll round
     * @param  (Closure(AgentJob): void)|null  $onRepair  called when a job starts its repair pass
     * @return list<TaskOutcome> in the order the jobs finished
     */
    public function run(array $jobs, ?Closure $onStarted = null, ?Closure $onFinished = null, ?Closure $onTick = null, ?Closure $onRepair = null): array
    {
        $keys = array_map(fn (AgentJob $job): string => $job->key, $jobs);
        $pending = self::criticalFirst($jobs);
        $running = [];
        $finished = [];
        $outcomes = [];

        while ($pending !== [] || $running !== []) {
            foreach ($pending as $index => $job) {
                if (count($running) >= max(1, $this->concurrency)) {
                    break;
                }

                if (! $this->ready($job, $finished, $keys)) {
                    continue;
                }

                unset($pending[$index]);
                $request = $job->request($finished);

                if ($request === null) {
                    // Nothing to do for this job (e.g. no findings to verify).
                    $outcome = TaskOutcome::immediate($job, AuditStatus::Completed, 'Nothing to do.');
                    $finished[$job->key] = $outcome;
                    $outcomes[] = $outcome;

                    continue;
                }

                $onStarted?->__invoke($job);
                $now = microtime(true);
                $running[] = $this->processes->start($job, $request, $request->prompt, $this->driver->command($request), false, $now);
            }

            if ($running === [] && $pending !== []) {
                // Nothing runs and nothing can start: a dependency cycle.
                foreach ($pending as $job) {
                    $outcome = TaskOutcome::immediate($job, AuditStatus::Failed, 'Waiting for tasks that never finished: '.implode(', ', $job->dependsOn).'.');
                    $finished[$job->key] = $outcome;
                    $outcomes[] = $outcome;
                    $onFinished?->__invoke($outcome);
                }

                break;
            }

            $stillRunning = [];

            foreach ($running as $attempt) {
                $result = $this->processes->poll($attempt);

                if ($result === null) {
                    $stillRunning[] = $attempt;

                    continue;
                }

                $next = $this->handle($attempt, $result);

                if ($next instanceof Attempt) {
                    $stillRunning[] = $next;
                    $onRepair?->__invoke($next->job);

                    continue;
                }

                $finished[$next->key] = $next;
                $outcomes[] = $next;
                $onFinished?->__invoke($next);
            }

            $running = $stillRunning;
            $onTick?->__invoke();

            if ($running !== []) {
                usleep($this->pollMicroseconds);
            }
        }

        return $outcomes;
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
            if (isset($chains[$key])) {
                return $chains[$key];
            }

            $longest = 0;

            foreach ($dependents[$key] ?? [] as $dependent) {
                // A cycle never finishes anyway; the run loop reports it.
                $longest = in_array($dependent, $seen, true) ? $longest : max($longest, $chain($dependent, [...$seen, $key]));
            }

            return $chains[$key] = $longest + 1;
        };

        $order = array_keys($jobs);
        usort($order, fn (int $a, int $b): int => [$chain($jobs[$b]->key), $a] <=> [$chain($jobs[$a]->key), $b]);

        return array_map(fn (int $index): AgentJob => $jobs[$index], $order);
    }

    /**
     * @param  array<string, TaskOutcome>  $finished
     * @param  list<string>  $keys  all job keys; dependencies outside this run are ignored
     */
    private function ready(AgentJob $job, array $finished, array $keys): bool
    {
        foreach ($job->dependsOn as $dependency) {
            if (in_array($dependency, $keys, true) && ! isset($finished[$dependency])) {
                return false;
            }
        }

        return true;
    }

    private function handle(Attempt $attempt, AgentResult $result): Attempt|TaskOutcome
    {
        $job = $attempt->job;
        $telemetry = $attempt->telemetry->plus($result->telemetry);

        // At the turn limit the agent answers from what it has, once.
        if ($result->exit === AgentExit::MaxTurns && ! $attempt->repair && $result->sessionId !== null) {
            $message = sprintf(self::ANSWER_NOW_MESSAGE, $job->spec->firstSection());

            return $this->processes->start($job, $attempt->request, $message, $this->driver->resumeCommand($result, $attempt->request), true, $attempt->jobStartedAt, $telemetry);
        }

        if (! $result->succeeded()) {
            $reason = $result->truncated ? 'Answer truncated (output token limit).' : ($result->error ?? $result->exit->value);

            return TaskOutcome::fromAttempt($attempt, AuditStatus::Failed, $reason, null, $telemetry, $result);
        }

        $validated = $this->validator->validate($result->answer, $job->spec, $job->required);

        if ($validated->lint->isValid()) {
            return TaskOutcome::fromAttempt($attempt, AuditStatus::Completed, null, $validated, $telemetry, $result);
        }

        if ($attempt->repair || $result->sessionId === null) {
            return TaskOutcome::fromAttempt($attempt, AuditStatus::Failed, 'Report failed validation'.($attempt->repair ? ' after the repair pass.' : '.'), $validated, $telemetry, $result);
        }

        $message = sprintf(self::REPAIR_MESSAGE, $job->spec->firstSection())."\n".implode("\n", array_map(fn (string $error): string => '- '.$error, $validated->lint->errors));

        return $this->processes->start($job, $attempt->request, $message, $this->driver->resumeCommand($result, $attempt->request), true, $attempt->jobStartedAt, $telemetry);
    }
}
