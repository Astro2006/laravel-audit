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
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\PromptRenderer;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Reports\AuditAssembler;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

/**
 * Splits the selected audits into agent jobs, one per task, and puts the
 * task outcomes back together into one outcome per audit.
 */
final class AuditTasks
{
    /** @var array<string, list<AuditTask>> audit key => tasks */
    private array $tasks = [];

    /** @var array<string, list<RequiredFinding>> audit key => required findings */
    private array $required = [];

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
        private readonly AuditAssembler $assembler = new AuditAssembler,
        private readonly ReportParser $parser = new ReportParser,
    ) {}

    /** @return list<AgentJob> */
    public function jobs(): array
    {
        $jobs = [];

        foreach ($this->audits as $type) {
            $audit = $type->audit();
            $tasks = $audit->tasks($this->context->customChecksFor($type->value) !== []);
            $this->tasks[$type->value] = $tasks;
            $this->required[$type->value] = $audit->requiredFindings($this->preflight);

            foreach ($tasks as $task) {
                $required = array_values(array_filter($this->required[$type->value], fn (RequiredFinding $finding): bool => in_array($finding->check, $task->checks, true)));

                $jobs[] = new AgentJob(
                    key: $task->key(),
                    audit: $type,
                    spec: ReportSpec::forTask($audit, $task, max($this->maxFindingsPerTask, count($required) + 2)),
                    request: fn (array $finished): AgentRequest => $this->request($audit, $task, $finished),
                    promptHash: $this->renderer->taskHash($audit, $task),
                    required: $required,
                    dependsOn: $task->dependencyKeys(),
                );
            }
        }

        return $jobs;
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

        $result = [];

        foreach ($this->audits as $type) {
            $audit = $type->audit();
            $tasks = $this->tasks[$type->value] ?? $audit->tasks();
            $summaryTask = $tasks[count($tasks) - 1];
            $summary = $byKey[$summaryTask->key()] ?? null;
            [$summaryText, $summarySections] = $this->summaryParts($summary, $summaryTask);

            $report = $this->assembler->assemble($audit, $tasks, $byKey, $summaryText, $summarySections);
            $required = array_values(array_filter(
                $this->required[$type->value] ?? [],
                fn (RequiredFinding $finding): bool => $this->ownedByCompletedTask($finding, $tasks, $report->completedTasks),
            ));
            $validated = $this->validator->validate($report->body, ReportSpec::forAudit($audit), $required);

            $taskOutcomes = array_values(array_filter(array_map(fn (AuditTask $task): ?TaskOutcome => $byKey[$task->key()] ?? null, $tasks)));
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

    /** @param array<string, TaskOutcome> $finished */
    private function summaryPrompt(Audit $audit, AuditTask $task, array $finished): RenderedPrompt
    {
        $tasks = $this->tasks[$audit->type()->value] ?? $audit->tasks();
        $report = $this->assembler->assemble($audit, $tasks, $finished);
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
