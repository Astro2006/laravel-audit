<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

final class AgentProcesses
{
    private readonly TaskLog $logs;

    public function __construct(
        private readonly AgentDriver $driver,
        string $logsDir,
        private readonly int $timeout,
        private readonly ChildEnvironment $environment,
    ) {
        $this->logs = new TaskLog($logsDir);
    }

    /** @param list<string> $command */
    public function start(AgentJob $job, AgentRequest $request, string $input, array $command, bool $repair, float $jobStartedAt, ?Telemetry $previous = null): Attempt
    {
        $process = Process::path($request->workingDirectory)
            ->forever()
            ->env([...$this->environment->overrides(), ...$this->driver->environment($request), ...$request->environment, 'AUDIT_TASK' => $job->key])
            ->input($input)
            ->start($command);

        return new Attempt($job, $request, $process, microtime(true), $repair, $previous ?? new Telemetry, $jobStartedAt);
    }

    public function poll(Attempt $attempt): ?AgentResult
    {
        $elapsed = microtime(true) - $attempt->startedAt;

        if ($attempt->process->running()) {
            if ($elapsed <= $this->timeout) {
                return null;
            }

            $this->stop($attempt->process);
            $output = $attempt->process->output();
            $this->logs->write($this->logName($attempt), $output, $attempt->process->errorOutput());
            $partial = $this->driver->parse($output, 1);
            $telemetry = $partial->telemetry;

            return new AgentResult(
                '',
                $partial->sessionId,
                $partial->model,
                new Telemetry($telemetry->inputTokens, $telemetry->outputTokens, $telemetry->cacheReadTokens, $telemetry->costUsd, $telemetry->turns, (int) ($elapsed * 1000)),
                AgentExit::Timeout,
                error: sprintf('Agent timed out after %d seconds.', $this->timeout),
            );
        }

        $result = $attempt->process->wait();
        $this->logs->write($this->logName($attempt), $result->output(), $result->errorOutput());

        $parsed = $this->driver->parse($result->output(), (int) $result->exitCode());

        return $parsed->error !== null && $parsed->answer === '' && $result->errorOutput() !== ''
            ? AgentResult::failed($parsed->exit, $parsed->error.' '.mb_substr(trim($result->errorOutput()), 0, 300), (int) ($elapsed * 1000))
            : $parsed;
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
