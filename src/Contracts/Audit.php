<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

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

    /** @return list<PreflightStep> */
    public function preflightSteps(): array;

    /**
     * Facts from the preflight the report has to cover with a finding.
     *
     * @return list<RequiredFinding>
     */
    public function requiredFindings(PreflightSummary $preflight): array;
}
