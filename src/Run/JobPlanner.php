<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\ResultsAgent;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Splits the selected audits into agent jobs: one per task, one verify job
 * per task, and the results job once every audit is done. Tasks with
 * nothing to audit are skipped without an agent call.
 */
final class JobPlanner
{
    /** Key of the job that writes the run's overview once every selected audit is done. */
    public const string RESULTS_KEY = 'results';

    /** @param  string|null  $overridePath  published prompts */
    public function __construct(
        private readonly TaskRequests $requests,
        private readonly TaskSettings $settings,
        private readonly ?string $overridePath = null,
    ) {}

    /** @param  list<AuditType>  $audits */
    public function plan(array $audits, PreflightSummary $preflight, PromptContext $context, ProjectInspector $project): JobPlan
    {
        $jobs = [];
        $planned = [];
        $skipped = [];

        foreach ($audits as $type) {
            $audit = $type->audit($this->overridePath);
            $planned[$type->value] = $plannedAudit = new PlannedAudit(
                $audit,
                $audit->tasks($context->customChecksFor($type->value) !== []),
                $audit->requiredFindings($preflight),
                $audit->findingGuard($preflight, $project),
            );
            $verifyKeys = [];

            foreach ($plannedAudit->tasks as $task) {
                if ($task->isSummary() && $this->settings->summaryMode === TaskSettings::SUMMARY_GENERATED && $task->sections === []) {
                    continue;
                }

                $reason = $task->isSummary() ? null : $audit->skipReason($task, $preflight);

                if ($reason !== null) {
                    $skipped[$task->key()] = $reason;

                    continue;
                }

                // The summary reads the findings after the verify pass.
                $jobs[] = $this->taskJob($type, $plannedAudit, $task, $task->isSummary() ? $verifyKeys : [], $skipped);

                if ($this->settings->verify && ! $task->isSummary()) {
                    $jobs[] = $verify = $this->verifyJob($type, $plannedAudit, $task);
                    $verifyKeys[] = $verify->key;
                }
            }
        }

        if ($audits !== []) {
            $jobs[] = $this->resultsJob(array_map(fn (AgentJob $job): string => $job->key, $jobs), $planned, $skipped);
        }

        return new JobPlan($jobs, $planned, $skipped);
    }

    /**
     * @param  list<string>  $extraDependencies
     * @param  array<string, string>  $skipped  filled while planning; read when the job starts
     */
    private function taskJob(AuditType $type, PlannedAudit $planned, AuditTask $task, array $extraDependencies, array &$skipped): AgentJob
    {
        $required = $planned->requiredFor($task);

        return new AgentJob(
            key: $task->key(),
            audit: $type,
            spec: ReportSpec::forTask($planned->audit, $task, max($this->settings->maxFindingsPerTask, count($required) + 2), $planned->guard),
            request: function (array $finished) use ($planned, $task, &$skipped): AgentRequest {
                return $this->requests->task($planned->audit, $task, $planned->tasks, $skipped, $finished);
            },
            promptHash: $planned->audit->taskHash($task),
            required: $required,
            dependsOn: [...$task->dependencyKeys(), ...$extraDependencies],
        );
    }

    private function verifyJob(AuditType $type, PlannedAudit $planned, AuditTask $task): AgentJob
    {
        $key = $task->key().TaskOutcome::VERIFY_SUFFIX;

        return new AgentJob(
            key: $key,
            audit: $type,
            spec: new ReportSpec($key, $planned->audit->idPrefix(), [], ['Verdicts']),
            request: fn (array $finished): ?AgentRequest => $this->requests->verify($planned->audit, $task, $planned->required, $finished),
            promptHash: $planned->audit->verifyHash(),
            dependsOn: [$task->key()],
        );
    }

    /**
     * Runs once every job of every selected audit has finished, whatever their status.
     *
     * @param  list<string>  $dependsOn
     * @param  array<string, PlannedAudit>  $planned
     * @param  array<string, string>  $skipped
     */
    private function resultsJob(array $dependsOn, array $planned, array $skipped): AgentJob
    {
        return new AgentJob(
            key: self::RESULTS_KEY,
            audit: null,
            spec: new ReportSpec(self::RESULTS_KEY, 'RES', [], ['Summary']),
            request: fn (array $finished): AgentRequest => $this->requests->results($planned, $skipped, $finished),
            promptHash: (new ResultsAgent($this->overridePath))->hash(),
            dependsOn: $dependsOn,
        );
    }
}
