<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\Agent;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\AuditAssembler;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Reports\VerdictApplier;

/**
 * Puts the task outcomes back together into one outcome per audit, after
 * applying the verdicts of the verify jobs.
 */
final class OutcomeAssembler
{
    public function __construct(
        private readonly ReportValidator $validator,
        private readonly VerdictApplier $verdicts = new VerdictApplier,
        private readonly AuditAssembler $assembler = new AuditAssembler,
        private readonly ReportParser $parser = new ReportParser,
    ) {}

    /**
     * @param  list<TaskOutcome>  $outcomes  what AgentRunner::run() returned
     * @return list<AuditOutcome> in the order of the selected audits
     */
    public function outcomes(JobPlan $plan, array $outcomes): array
    {
        $byKey = [];

        foreach ($outcomes as $outcome) {
            $byKey[$outcome->key] = $outcome;
        }

        $byKey = $this->verdicts->apply($byKey);
        $result = [];

        foreach ($plan->audits as $key => $planned) {
            $result[] = $this->audit(AuditType::from($key), $planned, $byKey, $plan->skipped);
        }

        return $result;
    }

    /**
     * The run's overview, written by the results job once every selected
     * audit is done. Null when it did not run or did not complete.
     *
     * @param  list<TaskOutcome>  $outcomes
     */
    public function overview(array $outcomes): ?string
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->key === JobPlanner::RESULTS_KEY && $outcome->completed() && $outcome->body !== null) {
                return trim($this->parser->parse($outcome->body)->summary()) ?: null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, TaskOutcome>  $byKey
     * @param  array<string, string>  $skipped
     */
    private function audit(AuditType $type, PlannedAudit $planned, array $byKey, array $skipped): AuditOutcome
    {
        $audit = $planned->audit;
        $summaryTask = $planned->summaryTask();
        [$summaryText, $summarySections] = $this->summaryParts($byKey[$summaryTask->key()] ?? null, $summaryTask);

        $report = $this->assembler->assemble($audit, $planned->tasks, $byKey, $summaryText, $summarySections, $skipped);
        $required = array_values(array_filter(
            $planned->required,
            fn (RequiredFinding $finding): bool => $this->ownedByCompletedTask($finding, $planned->tasks, $report->completedTasks),
        ));
        $validated = $this->validator->validate($report->body, ReportSpec::forAudit($audit, null, $planned->guard), $required);

        $taskOutcomes = [];

        foreach ($planned->tasks as $task) {
            foreach ([$task->key(), $task->key().TaskOutcome::VERIFY_SUFFIX] as $key) {
                if (isset($byKey[$key])) {
                    $taskOutcomes[] = $byKey[$key];
                }
            }
        }

        $hash = Agent::auditHash(array_map(fn (AuditTask $task): string => $audit->taskHash($task), $planned->tasks));

        return AuditOutcome::fromTasks($type, $taskOutcomes, $report, $validated->body, $validated->lint, $hash);
    }

    /**
     * @return array{string|null, array<string, string>}
     */
    private function summaryParts(?TaskOutcome $summary, AuditTask $task): array
    {
        if ($summary === null || ! $summary->completed() || $summary->body === null) {
            return [null, []];
        }

        $report = $this->parser->parse($summary->body);
        $sections = [];

        foreach ($task->sections as $title) {
            $sections[$title] = trim((string) $report->section($title));
        }

        $text = trim($report->summary());

        return [$text === '' ? null : $text, $sections];
    }

    /**
     * @param  list<AuditTask>  $tasks
     * @param  list<string>  $completed
     */
    private function ownedByCompletedTask(RequiredFinding $finding, array $tasks, array $completed): bool
    {
        foreach ($tasks as $task) {
            if (in_array($finding->check, $task->checks, true)) {
                return in_array($task->key(), $completed, true);
            }
        }

        return false;
    }
}
