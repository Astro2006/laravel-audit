<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\PromptRenderer;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Reports\AuditAssembler;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\FindingEditor;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\Rejection;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Regex;

/**
 * Splits the selected audits into agent jobs, one per task plus one verify
 * job per task, and puts the task outcomes back together into one outcome
 * per audit. Tasks with nothing to audit are skipped without an agent call.
 */
final class AuditTasks
{
    public const string SUMMARY_GENERATED = 'generated';

    public const string SUMMARY_AGENT = 'agent';

    private const string VERDICT_ROW = '/^\|\s*([A-Z]{2,4}-\d{3})\s*\|\s*`?(keep|drop|downgrade)`?\s*\|\s*(.*?)\s*\|\s*$/i';

    /** @var array<string, list<AuditTask>> audit key => tasks */
    private array $tasks = [];

    /** @var array<string, list<RequiredFinding>> audit key => required findings */
    private array $required = [];

    /** @var array<string, FindingGuard|null> audit key => guard */
    private array $guards = [];

    /** @var array<string, string> task key => why it was skipped */
    private array $skipped = [];

    /**
     * @param  list<AuditType>  $audits
     * @param  list<string>  $extraDirectories
     */
    public function __construct(
        private readonly array $audits,
        private readonly PreflightSummary $preflight,
        private readonly PromptContext $context,
        private readonly PromptRenderer $renderer,
        private readonly ReportValidator $validator,
        private readonly AuditRun $run,
        private readonly string $workingDirectory,
        private readonly ?string $configFile,
        private readonly array $extraDirectories = [],
        private readonly int $maxFindingsPerTask = 8,
        private readonly string $summaryMode = self::SUMMARY_GENERATED,
        private readonly bool $verify = true,
        private readonly Severity $verifyMinSeverity = Severity::Medium,
        private readonly AuditAssembler $assembler = new AuditAssembler,
        private readonly ReportParser $parser = new ReportParser,
        private readonly FindingEditor $editor = new FindingEditor,
    ) {}

    /** @return list<AgentJob> */
    public function jobs(): array
    {
        $jobs = [];

        $project = new ProjectInspector($this->workingDirectory);

        foreach ($this->audits as $type) {
            $audit = $type->audit();
            $tasks = $audit->tasks($this->context->customChecksFor($type->value) !== []);
            $this->tasks[$type->value] = $tasks;
            $this->required[$type->value] = $audit->requiredFindings($this->preflight);
            $this->guards[$type->value] = $guard = $audit->findingGuard($this->preflight, $project);
            $verifyKeys = [];

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
                    request: fn (array $finished): AgentRequest => $this->request($audit, $task, $finished),
                    promptHash: $this->renderer->taskHash($audit, $task),
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
                        request: fn (array $finished): ?AgentRequest => $this->verifyRequest($audit, $task, $finished),
                        promptHash: $this->renderer->verifyHash(),
                        dependsOn: [$task->key()],
                    );
                }
            }
        }

        return $jobs;
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

        $byKey = $this->applyVerdicts($byKey);
        $result = [];

        foreach ($this->audits as $type) {
            $audit = $type->audit();
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

            $hash = PromptRenderer::auditHash(array_map(fn (AuditTask $task): string => $this->renderer->taskHash($audit, $task), $tasks));

            $result[] = AuditOutcome::fromTasks($type, $taskOutcomes, $report, $validated->body, $validated->lint, $hash);
        }

        return $result;
    }

    /** @param array<string, TaskOutcome> $finished */
    private function request(Audit $audit, AuditTask $task, array $finished): AgentRequest
    {
        if ($task->isSummary()) {
            $prompt = $this->summaryPrompt($audit, $task, $finished);
        } else {
            [$sections, $missing] = $this->dependencyContext($audit, $task, $finished);
            $prompt = $this->renderer->renderTask($audit, $task, $this->context, $sections, $missing);
        }

        file_put_contents($this->run->logsDir().'/'.$task->key().'.prompt.md', $prompt->text);

        return new AgentRequest($task->key(), $prompt->text, $this->workingDirectory, $this->configFile, $this->extraDirectories);
    }

    /**
     * The sections of the tasks this one depends on, and the dependencies
     * that did not complete.
     *
     * @param  array<string, TaskOutcome>  $finished
     * @return array{array<string, string>, list<string>}
     */
    private function dependencyContext(Audit $audit, AuditTask $task, array $finished): array
    {
        $sections = [];
        $missing = [];

        foreach ($this->tasks[$audit->type()->value] ?? [] as $dependency) {
            if (! in_array($dependency->name, $task->dependsOn, true)) {
                continue;
            }

            $outcome = $finished[$dependency->key()] ?? null;

            if ($outcome === null || ! $outcome->completed() || $outcome->body === null) {
                $missing[] = $dependency->key();

                continue;
            }

            $report = $this->parser->parse($outcome->body);

            foreach ($dependency->sections as $title) {
                $sections[$title] = (string) $report->section($title);
            }
        }

        return [$sections, $missing];
    }

    /**
     * The verify prompt with the task's findings at or above the minimum
     * severity that no preflight fact backs; null when there is nothing to
     * verify.
     *
     * @param  array<string, TaskOutcome>  $finished
     */
    private function verifyRequest(Audit $audit, AuditTask $task, array $finished): ?AgentRequest
    {
        $outcome = $finished[$task->key()] ?? null;

        if ($outcome === null || ! $outcome->completed() || $outcome->body === null) {
            return null;
        }

        // Findings that cover a preflight fact (advisory, failing test, …) are facts, not claims.
        $required = $this->required[$audit->type()->value] ?? [];
        $ids = array_map(
            fn (Finding $finding): string => $finding->id,
            array_filter($outcome->findings, fn (Finding $finding): bool => $finding->severity->rank() >= $this->verifyMinSeverity->rank()
                && array_filter($required, fn (RequiredFinding $requirement): bool => $requirement->isCoveredBy([$finding])) === []),
        );

        if ($ids === []) {
            return null;
        }

        $prompt = $this->renderer->renderVerify($audit, $task, $this->context, $this->editor->blocks($outcome->body, array_values($ids)));
        $key = $task->key().TaskOutcome::VERIFY_SUFFIX;
        file_put_contents($this->run->logsDir().'/'.$key.'.prompt.md', $prompt->text);

        return new AgentRequest($key, $prompt->text, $this->workingDirectory, $this->configFile, $this->extraDirectories);
    }

    /**
     * Applies the verdicts of finished verify jobs to the task they checked.
     * A verify job that failed changes nothing.
     *
     * @param  array<string, TaskOutcome>  $outcomes  by key
     * @return array<string, TaskOutcome>
     */
    private function applyVerdicts(array $outcomes): array
    {
        foreach ($outcomes as $key => $verify) {
            $task = $outcomes[substr($key, 0, -strlen(TaskOutcome::VERIFY_SUFFIX))] ?? null;

            if (! $verify->isVerify() || ! $verify->completed() || $verify->body === null || $task === null || $task->body === null) {
                continue;
            }

            $titles = [];

            foreach ($task->findings as $finding) {
                $titles[$finding->id] = $finding->title;
            }

            $dropped = [];
            $downgraded = [];
            $rejected = [];

            foreach (Regex::lines((string) $this->parser->parse($verify->body)->section('Verdicts')) as $line) {
                $row = Regex::groups(self::VERDICT_ROW, trim($line));

                if ($row === null || ! isset($titles[$row[0]])) {
                    continue;
                }

                [$id, $verdict, $reason] = $row;

                if (strcasecmp($verdict, 'drop') === 0) {
                    $dropped[] = $id;
                    $rejected[] = new Rejection($id, $titles[$id], 'verify pass: '.$reason);
                } elseif (strcasecmp($verdict, 'downgrade') === 0) {
                    $downgraded[] = $id;
                }
            }

            $body = $this->editor->downgrade($this->editor->remove($task->body, $dropped), $downgraded);
            $outcomes[$task->key] = $task->withVerdicts($body, $dropped, $downgraded, $rejected);
        }

        return $outcomes;
    }

    /** @param array<string, TaskOutcome> $finished */
    private function summaryPrompt(Audit $audit, AuditTask $task, array $finished): RenderedPrompt
    {
        $tasks = $this->tasks[$audit->type()->value] ?? $audit->tasks();
        $report = $this->assembler->assemble($audit, $tasks, $this->applyVerdicts($finished), skipped: $this->skipped);
        $lines = [];

        foreach ($this->parser->parse($report->body)->findings as $finding) {
            $lines[] = sprintf(
                '- %s · %s · %s · `%s` — %s (%s)',
                $finding->id,
                $finding->field('severity') ?? '?',
                $finding->field('confidence') ?? '?',
                $finding->field('check') ?? '?',
                $finding->title,
                implode(', ', array_map(fn (Location $location): string => (string) $location, Location::parseList((string) $finding->field('locations')))),
            );
        }

        return $this->renderer->renderSummary($audit, $task, $this->context, implode("\n", $lines), $report->failedTasks);
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
