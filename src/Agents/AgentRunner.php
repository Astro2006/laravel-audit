<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\ValidatedReport;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/**
 * Runs the agent jobs in parallel (up to the concurrency limit). A job
 * starts once the jobs it depends on have finished, whatever their status.
 * Every answer is validated, and each job gets one repair pass in the same
 * session.
 */
final class AgentRunner
{
    private const string REPAIR_MESSAGE = <<<'TEXT'
        Your report did not pass validation. Fix exactly these errors and return the complete corrected report as your final answer, starting with the `## %s` line. Do not audit again and do not change findings that are not affected.

        Errors:
        TEXT;

    private readonly TaskLog $logs;

    public function __construct(
        private readonly AgentDriver $driver,
        private readonly ReportValidator $validator,
        string $logsDir,
        private readonly int $concurrency = 4,
        private readonly int $timeout = 1800,
        private readonly int $pollMicroseconds = 250_000,
        private readonly ChildEnvironment $environment = new ChildEnvironment,
    ) {
        $this->logs = new TaskLog($logsDir);
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
        $pending = $jobs;
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
                    $outcome = $this->nothingToDo($job);
                    $finished[$job->key] = $outcome;
                    $outcomes[] = $outcome;

                    continue;
                }

                $onStarted?->__invoke($job);
                $now = microtime(true);
                $running[] = $this->start($job, $request, $request->prompt, $this->driver->command($request), false, $now);
            }

            if ($running === [] && $pending !== []) {
                // Nothing runs and nothing can start: a dependency cycle.
                foreach ($pending as $job) {
                    $outcome = $this->failedBeforeStart($job, 'Waiting for tasks that never finished: '.implode(', ', $job->dependsOn).'.');
                    $finished[$job->key] = $outcome;
                    $outcomes[] = $outcome;
                    $onFinished?->__invoke($outcome);
                }

                break;
            }

            $stillRunning = [];

            foreach ($running as $attempt) {
                $result = $this->poll($attempt);

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

    /** @param list<string> $command */
    private function start(AgentJob $job, AgentRequest $request, string $input, array $command, bool $repair, float $jobStartedAt, ?Telemetry $previous = null): Attempt
    {
        $process = Process::path($request->workingDirectory)
            ->timeout($this->timeout + 60)
            ->env([...$this->environment->overrides(), ...$this->driver->environment($request), 'AUDIT_TASK' => $job->key])
            ->input($input)
            ->start($command);

        return new Attempt($job, $request, $process, microtime(true), $repair, $previous ?? new Telemetry, $jobStartedAt);
    }

    /** Returns the finished result, or null while the agent is still running. */
    private function poll(Attempt $attempt): ?AgentResult
    {
        $elapsed = microtime(true) - $attempt->startedAt;

        if ($attempt->process->running()) {
            if ($elapsed <= $this->timeout) {
                return null;
            }

            $this->stop($attempt->process);
            $this->logs->write($this->logName($attempt), $attempt->process->output(), $attempt->process->errorOutput());

            return AgentResult::failed(AgentExit::Timeout, sprintf('Agent timed out after %d seconds.', $this->timeout), (int) ($elapsed * 1000));
        }

        $result = $attempt->process->wait();
        $this->logs->write($this->logName($attempt), $result->output(), $result->errorOutput());

        $parsed = $this->driver->parse($result->output(), (int) $result->exitCode());

        return $parsed->error !== null && $parsed->answer === '' && $result->errorOutput() !== ''
            ? AgentResult::failed($parsed->exit, $parsed->error.' '.mb_substr(trim($result->errorOutput()), 0, 300), (int) ($elapsed * 1000))
            : $parsed;
    }

    private function handle(Attempt $attempt, AgentResult $result): Attempt|TaskOutcome
    {
        $job = $attempt->job;
        $telemetry = $attempt->telemetry->plus($result->telemetry);

        if (! $result->succeeded()) {
            $reason = $result->truncated ? 'Answer truncated (output token limit).' : ($result->error ?? $result->exit->value);

            return $this->outcome($attempt, AuditStatus::Failed, $reason, null, $telemetry, $result);
        }

        $validated = $this->validator->validate($result->answer, $job->spec, $job->required);

        if ($validated->lint->isValid()) {
            return $this->outcome($attempt, AuditStatus::Completed, null, $validated, $telemetry, $result);
        }

        if ($attempt->repair || $result->sessionId === null) {
            return $this->outcome($attempt, AuditStatus::Failed, 'Report failed validation'.($attempt->repair ? ' after the repair pass.' : '.'), $validated, $telemetry, $result);
        }

        $message = sprintf(self::REPAIR_MESSAGE, $job->spec->firstSection())."\n".implode("\n", array_map(fn (string $error): string => '- '.$error, $validated->lint->errors));

        return $this->start($job, $attempt->request, $message, $this->driver->resumeCommand($result, $attempt->request), true, $attempt->jobStartedAt, $telemetry);
    }

    private function outcome(Attempt $attempt, AuditStatus $status, ?string $reason, ?ValidatedReport $report, Telemetry $telemetry, AgentResult $result): TaskOutcome
    {
        $job = $attempt->job;

        return new TaskOutcome(
            key: $job->key,
            audit: $job->audit,
            status: $status,
            reason: $reason,
            body: $report?->body,
            findings: $report === null ? [] : $report->lint->findings,
            errors: $report === null ? [] : $report->lint->errors,
            warnings: $report === null ? [] : $report->lint->warnings,
            telemetry: $telemetry,
            repaired: $attempt->repair,
            promptHash: $job->promptHash,
            model: $result->model,
            startedAt: $attempt->jobStartedAt,
            finishedAt: microtime(true),
            rejected: $report === null ? [] : $report->lint->rejected,
        );
    }

    private function nothingToDo(AgentJob $job): TaskOutcome
    {
        $now = microtime(true);

        return new TaskOutcome($job->key, $job->audit, AuditStatus::Completed, 'Nothing to do.', null, [], [], [], new Telemetry, false, $job->promptHash, null, $now, $now);
    }

    private function failedBeforeStart(AgentJob $job, string $reason): TaskOutcome
    {
        $now = microtime(true);

        return new TaskOutcome($job->key, $job->audit, AuditStatus::Failed, $reason, null, [], [], [], new Telemetry, false, $job->promptHash, null, $now, $now);
    }

    private function stop(InvokedProcess $process): void
    {
        if (method_exists($process, 'stop')) {
            $process->stop(10);
        } else {
            $process->signal(15);
        }
    }

    private function logName(Attempt $attempt): string
    {
        return $attempt->job->key.($attempt->repair ? '.repair' : '');
    }
}
