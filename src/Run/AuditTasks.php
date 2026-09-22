<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\Agent;
use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\ResultsAgent;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Reports\AuditAssembler;
use Astro2006\LaravelAudit\Reports\FindingEditor;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Reports\VerdictApplier;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Splits the selected audits into agent jobs, one per task plus one verify
 * job per task, and puts the task outcomes back together into one outcome
 * per audit. Tasks with nothing to audit are skipped without an agent call.
 */
final class AuditTasks
{
    public const string SUMMARY_GENERATED = 'generated';

    public const string SUMMARY_AGENT = 'agent';

    /** Key of the job that writes the run's overview once every selected audit is done. */
    public const string RESULTS_KEY = 'results';

    /** @var array<string, list<AuditTask>> audit key => tasks */
    private array $tasks = [];

    /** @var array<string, list<RequiredFinding>> audit key => required findings */
    private array $required = [];

    /** @var array<string, FindingGuard|null> audit key => guard */
    private array $guards = [];

    /** @var array<string, string> task key => why it was skipped */
    private array $skipped = [];

    private readonly VerdictApplier $verdicts;

    private readonly TaskRequests $requests;

    /**
     * @param  list<AuditType>  $audits
     * @param  list<string>  $extraDirectories
     */
    public function __construct(
        private readonly array $audits,
        private readonly PreflightSummary $preflight,
        private readonly PromptContext $context,
        private readonly ?string $overridePath,
        private readonly ReportValidator $validator,
        AuditRun $run,
        private readonly string $workingDirectory,
        ?string $configFile,
        array $extraDirectories = [],
        private readonly int $maxFindingsPerTask = 8,
        private readonly string $summaryMode = self::SUMMARY_GENERATED,
        private readonly bool $verify = true,
        Severity $verifyMinSeverity = Severity::Medium,
        private readonly AuditAssembler $assembler = new AuditAssembler,
        private readonly ReportParser $parser = new ReportParser,
        FindingEditor $editor = new FindingEditor,
    ) {
        $this->verdicts = new VerdictApplier($parser, $editor);
        $this->requests = new TaskRequests($context, $overridePath, $run, $workingDirectory, $configFile, $extraDirectories, $verifyMinSeverity, $assembler, $parser, $editor, $this->verdicts);
    }

    /** @return list<AgentJob> */
    public function jobs(): array
    {
        $jobs = [];
        $auditKeys = [];

        $project = new ProjectInspector($this->workingDirectory);

        foreach ($this->audits as $type) {
            $audit = $type->audit($this->overridePath);
            $tasks = $audit->tasks($this->context->customChecksFor($type->value) !== []);
            $this->tasks[$type->value] = $tasks;
            $this->required[$type->value] = $audit->requiredFindings($this->preflight);
            $this->guards[$type->value] = $guard = $audit->findingGuard($this->preflight, $project);
            $verifyKeys = [];
            $before = count($jobs);

            foreach ($tasks as $task) {
                if ($task->isSummary() && $this->summaryMode === self::SUMMARY_GENERATED && $task->sections === []) {
                    continue;
                }

                $reason = $task->isSummary() ? null : $audit->skipReason($task, $this->preflight);

                if ($reason !== null) {
                    $this->skipped[$task->key()] = $reason;

                    continue;
                }

                $required = array_values(array_filter($this->required[$type->value], fn (RequiredFinding $finding): bool => in_array($finding->check, $task->checks, true)));

                $jobs[] = new AgentJob(
                    key: $task->key(),
                    audit: $type,
                    spec: ReportSpec::forTask($audit, $task, max($this->maxFindingsPerTask, count($required) + 2), $guard),
                    request: fn (array $finished): AgentRequest => $this->requests->task($audit, $task, $this->tasks[$type->value], $this->skipped, $finished),
                    promptHash: $audit->taskHash($task),
                    required: $required,
                    // The summary reads the findings after the verify pass.
                    dependsOn: $task->isSummary() ? [...$task->dependencyKeys(), ...$verifyKeys] : $task->dependencyKeys(),
                );

                if ($this->verify && ! $task->isSummary()) {
                    $verifyKeys[] = $key = $task->key().TaskOutcome::VERIFY_SUFFIX;

                    $jobs[] = new AgentJob(
                        key: $key,
                        audit: $type,
                        spec: new ReportSpec($key, $audit->idPrefix(), [], ['Verdicts']),
                        request: fn (array $finished): ?AgentRequest => $this->requests->verify($audit, $task, $this->required[$type->value], $finished),
                        promptHash: $audit->verifyHash(),
                        dependsOn: [$task->key()],
                    );
                }
            }

            $auditKeys = [...$auditKeys, ...array_map(fn (AgentJob $job): string => $job->key, array_slice($jobs, $before))];
        }

        if ($this->audits !== []) {
            $jobs[] = $this->resultsJob($auditKeys);
        }

        return $jobs;
    }

    /**
     * Runs once every job of every selected audit has finished, whatever their status.
     *
     * @param  list<string>  $dependsOn
     */
    private function resultsJob(array $dependsOn): AgentJob
    {
        $agent = new ResultsAgent($this->overridePath);

        return new AgentJob(
            key: self::RESULTS_KEY,
            audit: null,
            spec: new ReportSpec(self::RESULTS_KEY, 'RES', [], ['Summary']),
            request: fn (array $finished): AgentRequest => $this->requests->results($this->selectedAudits(), $this->skipped, $finished),
            promptHash: $agent->hash(),
            dependsOn: $dependsOn,
        );
    }

    /** @return array<string, array{Audit, list<AuditTask>}> audit key => audit and its tasks */
    private function selectedAudits(): array
    {
        $audits = [];

        foreach ($this->audits as $type) {
            $audit = $type->audit($this->overridePath);
            $audits[$type->value] = [$audit, $this->tasks[$type->value] ?? $audit->tasks()];
        }

        return $audits;
    }

    /** @return array<string, string> task key => why it was skipped */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @param  list<TaskOutcome>  $outcomes
     * @return list<AuditOutcome> in the order of the selected audits
     */
    public function outcomes(array $outcomes): array
    {
        $byKey = [];

        foreach ($outcomes as $outcome) {
            $byKey[$outcome->key] = $outcome;
        }

        $byKey = $this->verdicts->apply($byKey);
        $result = [];

        foreach ($this->audits as $type) {
            $audit = $type->audit($this->overridePath);
            $tasks = $this->tasks[$type->value] ?? $audit->tasks();
            $summaryTask = $tasks[count($tasks) - 1];
            $summary = $byKey[$summaryTask->key()] ?? null;
            [$summaryText, $summarySections] = $this->summaryParts($summary, $summaryTask);

            $report = $this->assembler->assemble($audit, $tasks, $byKey, $summaryText, $summarySections, $this->skipped);
            $required = array_values(array_filter(
                $this->required[$type->value] ?? [],
                fn (RequiredFinding $finding): bool => $this->ownedByCompletedTask($finding, $tasks, $report->completedTasks),
            ));
            $validated = $this->validator->validate($report->body, ReportSpec::forAudit($audit, null, $this->guards[$type->value] ?? null), $required);

            $taskOutcomes = [];

            foreach ($tasks as $task) {
                foreach ([$task->key(), $task->key().TaskOutcome::VERIFY_SUFFIX] as $key) {
                    if (isset($byKey[$key])) {
                        $taskOutcomes[] = $byKey[$key];
                    }
                }
            }

            $hash = Agent::auditHash(array_map(fn (AuditTask $task): string => $audit->taskHash($task), $tasks));

            $result[] = AuditOutcome::fromTasks($type, $taskOutcomes, $report, $validated->body, $validated->lint, $hash);
        }

        return $result;
    }

    /**
     * The run's overview, written by the results job once every selected
     * audit is done. Null when it did not run or did not complete.
     *
     * @param  list<TaskOutcome>  $outcomes  the raw outcomes AgentRunner::run() returned
     */
    public function resultsSummary(array $outcomes): ?string
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->key === self::RESULTS_KEY && $outcome->completed() && $outcome->body !== null) {
                return trim($this->parser->parse($outcome->body)->summary()) ?: null;
            }
        }

        return null;
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
