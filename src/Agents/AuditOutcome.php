<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\AssembledReport;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\LintResult;

/**
 * The result of one audit: the assembled report of its tasks.
 */
final readonly class AuditOutcome
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $errors  validation errors left after the repair pass
     * @param  list<string>  $warnings
     * @param  list<TaskOutcome>  $tasks
     * @param  array<string, string>  $findingTasks  finding ID => task key
     */
    public function __construct(
        public AuditType $audit,
        public AuditStatus $status,
        public ?string $reason,
        public ?string $body,
        public array $findings,
        public array $errors,
        public array $warnings,
        public Telemetry $telemetry,
        public bool $repaired,
        public string $promptHash,
        public ?string $model,
        public array $tasks = [],
        public array $findingTasks = [],
    ) {}

    /**
     * Completed when every task completed, failed when no task that audits
     * checks completed, partial otherwise. The assembled report's lint is the
     * only source of findings and warnings.
     *
     * @param  list<TaskOutcome>  $tasks
     */
    public static function fromTasks(AuditType $audit, array $tasks, AssembledReport $report, string $body, LintResult $lint, string $promptHash): self
    {
        $failed = array_values(array_filter($tasks, fn (TaskOutcome $task): bool => ! $task->completed()));
        $status = match (true) {
            $failed === [] => AuditStatus::Completed,
            $report->completedTasks === [] => AuditStatus::Failed,
            default => AuditStatus::Partial,
        };

        $telemetry = new Telemetry;
        $models = [];

        foreach ($tasks as $task) {
            $telemetry = $telemetry->plus($task->telemetry);

            if ($task->model !== null) {
                $models[$task->model] = ($models[$task->model] ?? 0) + 1;
            }
        }

        $started = $tasks === [] ? 0.0 : min(array_map(fn (TaskOutcome $task): float => $task->startedAt, $tasks));
        $finished = $tasks === [] ? 0.0 : max(array_map(fn (TaskOutcome $task): float => $task->finishedAt, $tasks));
        arsort($models);

        return new self(
            audit: $audit,
            status: $status,
            reason: $failed === [] ? null : sprintf('%d of %d tasks failed: %s', count($failed), count($tasks), implode(', ', array_map(fn (TaskOutcome $task): string => $task->name(), $failed))),
            body: $status === AuditStatus::Failed ? null : $body,
            findings: $lint->findings,
            errors: $lint->errors,
            warnings: $lint->warnings,
            // Tasks run in parallel: the audit took wall-clock time, not the sum.
            telemetry: new Telemetry(
                inputTokens: $telemetry->inputTokens,
                outputTokens: $telemetry->outputTokens,
                cacheReadTokens: $telemetry->cacheReadTokens,
                costUsd: $telemetry->costUsd,
                turns: $telemetry->turns,
                durationMs: (int) round(max(0.0, $finished - $started) * 1000),
            ),
            repaired: array_filter($tasks, fn (TaskOutcome $task): bool => $task->repaired) !== [],
            promptHash: $promptHash,
            model: $models === [] ? null : (string) array_key_first($models),
            tasks: $tasks,
            findingTasks: $report->findingTasks,
        );
    }

    public function completed(): bool
    {
        return $this->status === AuditStatus::Completed;
    }
}
