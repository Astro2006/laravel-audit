<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Closure;

final class AgentRunner
{
    private const int MAX_ERRORS_IN_A_ROW = 2;

    private readonly AgentProcesses $processes;

    private readonly FollowUp $followUp;

    public function __construct(
        private readonly AgentDriver $driver,
        ReportValidator $validator,
        string $logsDir,
        private readonly int $concurrency = 4,
        int $timeout = 1800,
        private readonly int $pollMicroseconds = 250_000,
        ChildEnvironment $environment = new ChildEnvironment,
    ) {
        $this->processes = new AgentProcesses($driver, $logsDir, $timeout, $environment);
        $this->followUp = new FollowUp($validator);
    }

    /**
     * @param  list<AgentJob>  $jobs  in the order they should start
     * @param  (Closure(AgentJob): void)|null  $onStarted
     * @param  (Closure(TaskOutcome): void)|null  $onFinished
     * @param  (Closure(): void)|null  $onTick  called after every poll round
     * @param  (Closure(AgentJob): void)|null  $onRepair  called when a job starts its repair pass
     * @param  array<string, TaskOutcome>  $earlier  outcomes of jobs that finished in an earlier call; not part of the result
     * @return list<TaskOutcome> in the order the jobs finished
     */
    public function run(array $jobs, ?Closure $onStarted = null, ?Closure $onFinished = null, ?Closure $onTick = null, ?Closure $onRepair = null, array $earlier = []): array
    {
        $queue = new JobQueue($jobs);
        $running = [];
        $finished = $earlier;
        $outcomes = [];
        $errorsInARow = 0;

        while (! $queue->isEmpty() || $running !== []) {
            if ($errorsInARow >= self::MAX_ERRORS_IN_A_ROW) {
                foreach ($queue->drain() as $job) {
                    $this->record(TaskOutcome::immediate($job, AuditStatus::Failed, sprintf('Not started: the last %d agent calls failed, so the model is not answering.', $errorsInARow)), $finished, $outcomes, $onFinished);
                }
            }

            while (count($running) < max(1, $this->concurrency) && ($job = $queue->take($finished)) !== null) {
                $request = $job->request($finished);

                if ($request === null) {
                    $this->record(TaskOutcome::immediate($job, AuditStatus::Completed, 'Nothing to do.'), $finished, $outcomes, null);

                    continue;
                }

                $onStarted?->__invoke($job);
                $running[] = $this->processes->start($job, $request, $request->prompt, $this->driver->command($request), false, microtime(true));
            }

            if ($running === [] && ! $queue->isEmpty()) {
                foreach ($queue->drain() as $job) {
                    $this->record(TaskOutcome::immediate($job, AuditStatus::Failed, 'Waiting for tasks that never finished: '.implode(', ', $job->dependsOn).'.'), $finished, $outcomes, $onFinished);
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

                $errorsInARow = $result->exit === AgentExit::Error ? $errorsInARow + 1 : 0;
                $telemetry = $attempt->telemetry->plus($result->telemetry);
                $next = $this->followUp->decide($attempt, $result, $telemetry);

                if (is_string($next)) {
                    $stillRunning[] = $this->processes->start($attempt->job, $attempt->request, $next, $this->driver->resumeCommand($result, $attempt->request), true, $attempt->jobStartedAt, $telemetry);
                    $onRepair?->__invoke($attempt->job);

                    continue;
                }

                $this->record($next, $finished, $outcomes, $onFinished);
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
     * @param  array<string, TaskOutcome>  $finished
     * @param  list<TaskOutcome>  $outcomes
     * @param  (Closure(TaskOutcome): void)|null  $onFinished
     */
    private function record(TaskOutcome $outcome, array &$finished, array &$outcomes, ?Closure $onFinished): void
    {
        $finished[$outcome->key] = $outcome;
        $outcomes[] = $outcome;
        $onFinished?->__invoke($outcome);
    }
}
