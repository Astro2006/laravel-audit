<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * One audit domain (backend, frontend, dependencies, tests): what it checks,
 * how it splits into tasks, and how it renders its own prompts. One agent
 * class implements this per domain (Astro2006\LaravelAudit\Agents).
 */
interface Audit
{
    public function type(): AuditType;

    /** Finding ID prefix, e.g. "BE". */
    public function idPrefix(): string;

    public function title(): string;

    /**
     * Checks this audit owns. Every check belongs to exactly one audit.
     *
     * @return array<string, string> slug => short description
     */
    public function checks(): array;

    /**
     * The tasks the audit is split into, in report order. Every check except
     * `other` belongs to exactly one task; the summary task comes last.
     *
     * @param  bool  $withCustom  add the task for the project's custom checks
     * @return list<AuditTask>
     */
    public function tasks(bool $withCustom = false): array;

    /**
     * Audit-specific level-2 sections between "Findings" and "Positive observations".
     *
     * @return list<string>
     */
    public function sections(): array;

    /**
     * Facts from the preflight the report has to cover with a finding.
     *
     * @return list<RequiredFinding>
     */
    public function requiredFindings(PreflightSummary $preflight): array;

    /**
     * Why a task has nothing to audit in this run (e.g. no advisories), or
     * null when it runs. Skipped tasks cost no agent call.
     */
    public function skipReason(AuditTask $task, PreflightSummary $preflight): ?string;

    /** Facts from the preflight a finding must not contradict. */
    public function findingGuard(PreflightSummary $preflight, ProjectInspector $project): ?FindingGuard;

    /**
     * @param  array<string, string>  $dependencyContext  section title => content, from the tasks this one depends on
     * @param  list<string>  $missingDependencies  keys of dependencies that did not complete
     * @param  int|null  $maxFindings  the task's own limit, when it differs from the run's
     */
    public function renderTask(AuditTask $task, PromptContext $context, array $dependencyContext = [], array $missingDependencies = [], ?int $maxFindings = null): RenderedPrompt;

    /**
     * @param  string  $findings  the merged findings as a compact list
     * @param  list<string>  $failedTasks
     */
    public function renderSummary(AuditTask $task, PromptContext $context, string $findings, array $failedTasks): RenderedPrompt;

    /** @param  string  $findings  the findings to verify, as the task wrote them */
    public function renderVerify(AuditTask $task, PromptContext $context, string $findings): RenderedPrompt;

    /** Depends on the templates and the task definition only, never on the run. */
    public function taskHash(AuditTask $task): string;

    public function verifyHash(): string;
}
