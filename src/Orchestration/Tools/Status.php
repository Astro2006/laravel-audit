<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Orchestration\WorkerLock;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class Status
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @return array<string, mixed> */
    public function handle(OrchestratedRun $run): array
    {
        $lock = $run->lock->current();
        $preflight = $run->preflight();
        $steps = [];

        foreach (PreflightStep::cases() as $step) {
            $result = $preflight?->steps->get($step);
            $running = $lock !== null && Arr::get($lock, 'kind') === WorkerLock::PREFLIGHT && in_array($step->value, Arr::get($lock, 'items'), true);

            if ($result !== null || $running) {
                $steps[$step->value] = $result === null ? ['status' => 'running'] : self::step($result);
            }
        }

        $tasks = [];

        foreach (array_keys(RunTasks::for($run, $this->config)->tasks) as $key) {
            $tasks[$key] = self::task($run, $key, $lock);
        }

        return [
            'run' => $run->run->id,
            'worker' => $lock === null ? 'idle' : [
                'working_on' => Arr::get($lock, 'kind'),
                'items' => Arr::get($lock, 'items'),
                'running_for_s' => time() - Arr::get($lock, 'started_at'),
            ],
            'gate' => $preflight === null ? GateDecision::NotRun->value : $preflight->gate->value,
            ...($preflight?->gate === GateDecision::Aborted ? [
                'failing_tests' => TestGate::failures($preflight->steps),
                'gate_hint' => 'Tests fail. Show the user the failures (preflight/details/tests/) and ask whether to continue. Only on a yes: `continue`.',
            ] : []),
            'preflight' => $steps,
            'tasks' => $tasks,
        ];
    }

    /**
     * @param  array{kind: string, items: list<string>, pid: int|null, started_at: int}|null  $lock
     * @return array<string, mixed>
     */
    public static function task(OrchestratedRun $run, string $key, ?array $lock): array
    {
        $record = $run->store->record($key);

        if ($lock !== null && Arr::get($lock, 'kind') === WorkerLock::TASKS && in_array($key, Arr::get($lock, 'items'), true)) {
            return ['status' => 'running'];
        }

        if ($record === null) {
            return ['status' => 'not_started'];
        }

        $severities = [];

        foreach (Read::arrays($record, 'findings') as $finding) {
            $severity = Read::string($finding, 'severity', 'unknown');
            $severities[$severity] = Arr::get($severities, $severity, 0) + 1;
        }

        return array_filter([
            'status' => Read::string($record, 'status'),
            'reason' => Read::nullableString($record, 'reason'),
            'findings' => count(Read::arrays($record, 'findings')),
            'by_severity' => $severities,
            'duration_s' => round(Read::float($record, 'finished_at') - Read::float($record, 'started_at'), 1),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    private static function step(StepResult $result): array
    {
        return array_filter([
            'status' => $result->status->value,
            'reason' => $result->reason?->value,
            'error' => $result->error,
            'duration_s' => round($result->duration, 1),
        ], fn (mixed $value): bool => $value !== null);
    }
}
