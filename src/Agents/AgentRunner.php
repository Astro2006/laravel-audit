<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\ValidatedReport;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/**
 * Runs the audit agents in parallel (up to the concurrency limit), validates
 * every answer and gives each audit one repair pass in the same session.
 */
final class AgentRunner
{
    private const string REPAIR_MESSAGE = <<<'TEXT'
        Your report did not pass validation. Fix exactly these errors and return the complete corrected report as your final answer, starting with the `## Summary` line. Do not audit again and do not change findings that are not affected.

        Errors:
        TEXT;

    public function __construct(
        private readonly AgentDriver $driver,
        private readonly ReportValidator $validator,
        private readonly string $logsDir,
        private readonly int $concurrency = 4,
        private readonly int $timeout = 1800,
        private readonly int $pollMicroseconds = 250_000,
    ) {}

    /**
     * @param  list<AgentJob>  $jobs
     * @param  (Closure(AgentJob): void)|null  $onStarted
     * @param  (Closure(AuditOutcome): void)|null  $onFinished
     * @return array<string, AuditOutcome> audit key => outcome
     */
    public function run(array $jobs, ?Closure $onStarted = null, ?Closure $onFinished = null): array
    {
        $queue = $jobs;
        $running = [];
        $outcomes = [];

        while ($queue !== [] || $running !== []) {
            while (count($running) < max(1, $this->concurrency) && $queue !== []) {
                $job = array_shift($queue);
                $onStarted?->__invoke($job);
                $running[$job->audit->type()->value] = $this->start($job, $job->request->prompt, $this->driver->command($job->request), false);
            }

            foreach ($running as $key => $attempt) {
                $result = $this->poll($attempt);

                if ($result === null) {
                    continue;
                }

                unset($running[$key]);
                $next = $this->handle($attempt, $result);

                if ($next instanceof Attempt) {
                    $running[$key] = $next;

                    continue;
                }

                $outcomes[$key] = $next;
                $onFinished?->__invoke($next);
            }

            if ($running !== []) {
                usleep($this->pollMicroseconds);
            }
        }

        return $outcomes;
    }

    /** @param list<string> $command */
    private function start(AgentJob $job, string $input, array $command, bool $repair, ?Telemetry $previous = null): Attempt
    {
        $process = Process::path($job->request->workingDirectory)
            ->timeout($this->timeout + 60)
            ->env($this->driver->environment())
            ->input($input)
            ->start($command);

        return new Attempt($job, $process, microtime(true), $repair, $previous ?? new Telemetry);
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
            $this->log($attempt, $attempt->process->output(), $attempt->process->errorOutput());

            return AgentResult::failed(AgentExit::Timeout, sprintf('Agent timed out after %d seconds.', $this->timeout), (int) ($elapsed * 1000));
        }

        $result = $attempt->process->wait();
        $this->log($attempt, $result->output(), $result->errorOutput());

        $parsed = $this->driver->parse($result->output(), (int) $result->exitCode());

        return $parsed->error !== null && $parsed->answer === '' && $result->errorOutput() !== ''
            ? AgentResult::failed($parsed->exit, $parsed->error.' '.mb_substr(trim($result->errorOutput()), 0, 300), (int) ($elapsed * 1000))
            : $parsed;
    }

    private function handle(Attempt $attempt, AgentResult $result): Attempt|AuditOutcome
    {
        $job = $attempt->job;
        $telemetry = $attempt->telemetry->plus($result->telemetry);

        if (! $result->succeeded()) {
            $reason = $result->truncated ? 'Answer truncated (output token limit).' : ($result->error ?? $result->exit->value);

            return $this->outcome($job, AuditStatus::Failed, $reason, null, $telemetry, $attempt->repair, $result);
        }

        $validated = $this->validator->validate($result->answer, $job->audit, $job->required);

        if ($validated->lint->isValid()) {
            return $this->outcome($job, AuditStatus::Completed, null, $validated, $telemetry, $attempt->repair, $result);
        }

        if ($attempt->repair || $result->sessionId === null) {
            return $this->outcome($job, AuditStatus::Failed, 'Report failed validation after the repair pass.', $validated, $telemetry, $attempt->repair, $result);
        }

        $message = self::REPAIR_MESSAGE."\n".implode("\n", array_map(fn (string $error): string => '- '.$error, $validated->lint->errors));

        return $this->start($job, $message, $this->driver->resumeCommand($result, $job->request), true, $telemetry);
    }

    private function outcome(AgentJob $job, AuditStatus $status, ?string $reason, ?ValidatedReport $report, Telemetry $telemetry, bool $repaired, AgentResult $result): AuditOutcome
    {
        return new AuditOutcome(
            audit: $job->audit->type(),
            status: $status,
            reason: $reason,
            body: $report?->body,
            findings: $report === null ? [] : $report->lint->findings,
            errors: $report === null ? [] : $report->lint->errors,
            warnings: $report === null ? [] : $report->lint->warnings,
            telemetry: $telemetry,
            repaired: $repaired,
            promptHash: $job->promptHash,
            model: $result->model,
        );
    }

    private function stop(InvokedProcess $process): void
    {
        if (method_exists($process, 'stop')) {
            $process->stop(10);
        } else {
            $process->signal(15);
        }
    }

    private function log(Attempt $attempt, string $output, string $errorOutput): void
    {
        if (! is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0777, true);
        }

        $name = $attempt->job->audit->type()->value.($attempt->repair ? '.repair' : '');

        file_put_contents($this->logsDir.'/'.$name.'.json', $output);

        if (trim($errorOutput) !== '') {
            file_put_contents($this->logsDir.'/'.$name.'.stderr.log', $errorOutput);
        }
    }
}
