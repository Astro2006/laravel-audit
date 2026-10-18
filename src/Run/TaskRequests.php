<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\Audit;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Prompts\OverviewPrompt;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Reports\AuditAssembler;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\FindingDigest;
use Astro2006\LaravelAudit\Reports\FindingEditor;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Reports\VerdictApplier;
use Astro2006\LaravelAudit\Reports\VerifyPolicy;
use Illuminate\Support\Arr;

final class TaskRequests
{
    /** @param list<string> $extraDirectories */
    public function __construct(
        private readonly PromptContext $context,
        private readonly ?string $overridePath,
        private readonly AuditRun $run,
        private readonly string $workingDirectory,
        private readonly ?string $configFile,
        private readonly array $extraDirectories,
        private readonly AuditAssembler $assembler = new AuditAssembler,
        private readonly ReportParser $parser = new ReportParser,
        private readonly FindingEditor $editor = new FindingEditor,
        private readonly VerdictApplier $verdicts = new VerdictApplier,
        private readonly VerifyPolicy $policy = new VerifyPolicy,
    ) {}

    /**
     * @param  list<AuditTask>  $tasks  every task of the audit
     * @param  array<string, string>  $skipped  task key => why it was skipped
     * @param  array<string, TaskOutcome>  $finished
     * @param  int|null  $maxFindings  the limit the task's report is validated against
     */
    public function task(Audit $audit, AuditTask $task, array $tasks, array $skipped, array $finished, ?int $maxFindings = null): AgentRequest
    {
        if ($task->isSummary()) {
            $prompt = $this->summaryPrompt($audit, $task, $tasks, $skipped, $finished);
        } else {
            [$sections, $missing] = $this->dependencyContext($task, $tasks, $finished);
            $prompt = $audit->renderTask($task, $this->context, $sections, $missing, $maxFindings);
        }

        return $this->request($task->key(), $prompt, maxTurns: $task->maxTurns);
    }

    /**
     * The verify prompt with the task's findings the verify policy picks and
     * no preflight fact backs; null when there is nothing to verify.
     *
     * @param  list<RequiredFinding>  $required
     * @param  array<string, TaskOutcome>  $finished
     */
    public function verify(Audit $audit, AuditTask $task, array $required, array $finished): ?AgentRequest
    {
        $outcome = Arr::get($finished, $task->key());

        if ($outcome === null || ! $outcome->completed() || $outcome->body === null) {
            return null;
        }

        $ids = array_map(
            fn (Finding $finding): string => $finding->id,
            array_filter($outcome->findings, fn (Finding $finding): bool => $this->policy->needsVerify($finding)
                && array_filter($required, fn (RequiredFinding $requirement): bool => $requirement->isCoveredBy([$finding])) === []),
        );

        if ($ids === []) {
            return null;
        }

        return $this->request(
            $task->key().TaskOutcome::VERIFY_SUFFIX,
            $audit->renderVerify($task, $this->context, $this->editor->blocks($outcome->body, array_values($ids))),
            verify: true,
        );
    }

    /**
     * @param  array<string, PlannedAudit>  $audits
     * @param  array<string, string>  $skipped
     * @param  array<string, TaskOutcome>  $finished
     */
    public function results(array $audits, array $skipped, array $finished): AgentRequest
    {
        $digests = [];

        foreach ($audits as $planned) {
            $audit = $planned->audit;
            $report = $this->assembler->assemble($audit, $planned->tasks, $this->verdicts->apply($finished), skipped: $skipped);
            $parsed = $this->parser->parse($report->body);

            $digests[] = sprintf("### %s (%s)\n\n%s\n\n%s", $audit->title(), $audit->idPrefix(), $parsed->summary() ?: 'No summary.', FindingDigest::table($parsed->findings));
        }

        return $this->request(JobPlanner::RESULTS_KEY, (new OverviewPrompt($this->overridePath))->render($this->context, $digests));
    }

    private function request(string $key, RenderedPrompt $prompt, bool $verify = false, ?int $maxTurns = null): AgentRequest
    {
        file_put_contents($this->run->logsDir().'/'.$key.'.prompt.md', $prompt->text);

        return new AgentRequest($key, $prompt->text, $this->workingDirectory, $this->configFile, $this->extraDirectories, verify: $verify, maxTurns: $maxTurns);
    }

    /**
     * The sections of the tasks this one depends on, and the dependencies
     * that did not complete.
     *
     * @param  list<AuditTask>  $tasks
     * @param  array<string, TaskOutcome>  $finished
     * @return array{array<string, string>, list<string>}
     */
    private function dependencyContext(AuditTask $task, array $tasks, array $finished): array
    {
        $sections = [];
        $missing = [];

        foreach ($tasks as $dependency) {
            if (! in_array($dependency->name, $task->dependsOn, true)) {
                continue;
            }

            $outcome = Arr::get($finished, $dependency->key());

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
     * @param  list<AuditTask>  $tasks
     * @param  array<string, string>  $skipped
     * @param  array<string, TaskOutcome>  $finished
     */
    private function summaryPrompt(Audit $audit, AuditTask $task, array $tasks, array $skipped, array $finished): RenderedPrompt
    {
        $report = $this->assembler->assemble($audit, $tasks, $this->verdicts->apply($finished), skipped: $skipped);
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

        return $audit->renderSummary($task, $this->context, implode("\n", $lines), $report->failedTasks);
    }
}
