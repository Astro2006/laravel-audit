<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\Stage;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class Status
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @return array<string, mixed> */
    public function handle(OpenedRun $run): array
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

        $runTasks = RunTasks::for($run, $this->config);
        $tasks = [];
        $pending = [];
        $stages = array_fill_keys(Stage::values(), ['done' => 0, 'total' => 0]);

        foreach ($runTasks->tasks as $key => $task) {
            $tasks[$key] = self::task($run, $key, $lock);
            $stage = $task->stage->value;
            $stages[$stage]['total'] = Read::int($stages, $stage.'.total') + 1;

            if ($run->store->record($key) !== null) {
                $stages[$stage]['done'] = Read::int($stages, $stage.'.done') + 1;
            } elseif ($preflight === null || Arr::get($runTasks->audits, $key)->skipReason($task, $preflight) === null) {
                $pending[] = $key;
            }
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
            'stages' => $stages,
            'tasks' => $tasks,
            'pending' => $pending,
            'spend_usd' => $run->store->spendUsd(),
            'next' => (new NextStep($this->config))->for($run),
        ];
    }

    /**
     * @param  array{kind: string, items: list<string>, pid: int|null, started_at: int}|null  $lock
     * @return array<string, mixed>
     */
    public static function task(OpenedRun $run, string $key, ?array $lock): array
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
