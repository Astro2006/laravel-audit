<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\RunPause;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\Overlap;
use Astro2006\LaravelAudit\Reports\Rejection;
use Astro2006\LaravelAudit\Support\Json;
use DateTimeImmutable;

final class RunManifest
{
    public RunStatus $status = RunStatus::Running;

    public ?DateTimeImmutable $finishedAt = null;

    public ?string $preflightFrom = null;

    public bool $preflightComplete = false;

    public GateDecision $gate = GateDecision::NotRun;

    public ?string $browser = null;

    public ?string $model = null;

    public ?string $overview = null;

    public ?RunPause $pause = null;

    public StepResults $steps;

    /** @var list<Metric> */
    public array $metrics = [];

    /** @var list<AuditOutcome> */
    public array $outcomes = [];

    /** @var list<Overlap> */
    public array $overlaps = [];

    public function __construct(
        public readonly AuditRun $run,
        public readonly RunSelection $selection,
        public readonly GitInfo $git,
        public readonly string $agent,
        public readonly string $packageVersion,
    ) {
        $this->steps = new StepResults;
    }

    public function finish(RunStatus $status): void
    {
        $this->status = $status;
        $this->finishedAt = new DateTimeImmutable;
    }

    public function close(RunStatus $status): void
    {
        $this->finish($status);
        $this->write();
    }

    public function write(): void
    {
        file_put_contents($this->run->manifestPath(), $this->render());
        Json::writeFile($this->run->dir().'/run.json', $this->toArray());

        if ($this->outcomes !== []) {
            file_put_contents($this->run->auditPath(), (new AuditMarkdown($this))->render());
        }
    }

    public function resultPath(): string
    {
        return $this->outcomes === [] ? $this->run->manifestPath() : $this->run->auditPath();
    }

    /**
     * Machine-readable twin of run.md, used by audit:eval.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->run->id,
            'status' => $this->status->value,
            'audits' => $this->selection->auditValues(),
            'without' => $this->selection->withoutValues(),
            'preflight_from' => $this->preflightFrom,
            'gate' => $this->gate->value,
            'agent' => $this->agent,
            'model' => $this->model,
            'overview' => $this->overview,
            'paused' => $this->pause?->toArray(),
            'git' => ['branch' => $this->git->branch, 'commit' => $this->git->commit, 'dirty' => $this->git->dirty],
            'outcomes' => array_map(fn (AuditOutcome $outcome): array => [
                'audit' => $outcome->audit->value,
                'status' => $outcome->status->value,
                'reason' => $outcome->reason,
                'repaired' => $outcome->repaired,
                'prompt_hash' => $outcome->promptHash,
                'model' => $outcome->model,
                'telemetry' => $outcome->telemetry->toArray(),
                'findings' => array_map(fn (Finding $finding): array => [
                    'key' => $finding->key($outcome->audit->value),
                    ...$finding->toArray(),
                    'targets' => array_values(array_unique(array_map(fn (Location $location): string => $location->target(), $finding->locations))),
                ], $outcome->findings),
                'errors' => $outcome->errors,
                'warnings' => $outcome->warnings,
                'tasks' => array_map(fn (TaskOutcome $task): array => [
                    'key' => $task->key,
                    'status' => $task->status->value,
                    'reason' => $task->reason,
                    'repaired' => $task->repaired,
                    'prompt_hash' => $task->promptHash,
                    'model' => $task->model,
                    'findings' => count($task->findings),
                    'telemetry' => $task->telemetry->toArray(),
                    'wall_clock_seconds' => self::wallClock($task),
                    'errors' => $task->errors,
                    'rejected' => array_map(fn (Rejection $rejection): array => $rejection->toArray(), $task->rejected),
                ], $outcome->tasks),
            ], $this->outcomes),
            'overlaps' => count($this->overlaps),
        ];
    }

    public function render(): string
    {
        return (new RunMarkdown($this))->render();
    }

    /**
     * @param  list<Finding>  $findings
     * @return array{critical: int, high: int, medium: int, low: int, info: int}
     */
    public static function severityCounts(array $findings): array
    {
        $count = fn (string $severity): int => count(array_filter($findings, fn (Finding $finding): bool => $finding->severity->value === $severity));

        return ['critical' => $count('critical'), 'high' => $count('high'), 'medium' => $count('medium'), 'low' => $count('low'), 'info' => $count('info')];
    }

    public static function wallClock(TaskOutcome $task): float
    {
        return round(max(0.0, $task->finishedAt - $task->startedAt), 1);
    }
}
