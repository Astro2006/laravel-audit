<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final class ReportLinter
{
    private readonly FindingRejector $rejector;

    private readonly FindingLinter $findings;

    public function __construct(string $basePath, EvidenceChecker $evidence = new EvidenceChecker)
    {
        $this->rejector = new FindingRejector($basePath, $evidence);
        $this->findings = new FindingLinter($basePath);
    }

    /** @param list<RequiredFinding> $required */
    public function lint(ParsedReport $report, ReportSpec $spec, array $required = []): LintResult
    {
        $errors = $this->sectionErrors($report, $spec);
        $warnings = [];

        if (! $spec->hasFindings()) {
            return new LintResult($errors, $this->summaryWarnings($report), []);
        }

        foreach ($report->invalidHeadings as $heading) {
            $errors[] = sprintf(
                'Line %d: "%s" is not a valid finding heading. Use `### %s-001 — Title`.',
                $heading->line,
                trim($heading->text),
                $spec->idPrefix,
            );
        }

        $ids = array_map(fn (ParsedFinding $finding): string => $finding->id, $report->findings);
        $seen = [];
        $findings = [];
        $rejected = [];

        foreach ($report->findings as $parsed) {
            if (in_array($parsed->id, $seen, true)) {
                $errors[] = sprintf('%s: duplicate finding ID.', $parsed->id);

                continue;
            }

            $seen[] = $parsed->id;

            [$finding, $findingErrors, $findingWarnings] = $this->findings->lint($parsed, $spec, $ids);
            array_push($errors, ...$findingErrors);
            array_push($warnings, ...$findingWarnings);

            if ($finding === null) {
                continue;
            }

            $reason = $this->rejector->reason($finding, $spec);

            if ($reason === null) {
                $findings[] = $finding;
            } else {
                $rejected[] = new Rejection($finding->id, $finding->title, $reason);
                $warnings[] = sprintf('%s: rejected — %s', $finding->id, $reason);
            }
        }

        if ($spec->maxFindings !== null && count($report->findings) > $spec->maxFindings) {
            $errors[] = sprintf(
                'The report has %d findings; the maximum is %d. Group similar findings into one finding with several locations and an Occurrences count.',
                count($report->findings),
                $spec->maxFindings,
            );
        }

        if ($report->findings === [] && ! $report->noFindingsMarker && $report->hasSection('Findings')) {
            $warnings[] = 'No findings and no "No findings." line under `## Findings`.';
        }

        foreach ($required as $requirement) {
            if (! $requirement->isCoveredBy($findings)) {
                $errors[] = 'Missing required finding: '.$requirement->description;
            }
        }

        return new LintResult($errors, [...$warnings, ...$this->summaryWarnings($report)], Finding::withUniqueKeys($findings), $rejected);
    }

    /** @return list<string> */
    private function summaryWarnings(ParsedReport $report): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $report->summary(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($sentences) > 5 ? [sprintf('Summary has %d sentences; the limit is 5.', count($sentences))] : [];
    }

    /** @return list<string> */
    private function sectionErrors(ParsedReport $report, ReportSpec $spec): array
    {
        $errors = [];

        foreach ($spec->sections as $section) {
            if (! $report->hasSection($section)) {
                $errors[] = sprintf('Missing section `## %s`.', $section);
            }
        }

        $last = $report->lastSection();

        if (in_array('Limitations', $spec->sections, true) && $report->hasSection('Limitations') && ($last === null || ! $last->is('Limitations'))) {
            $errors[] = '`## Limitations` must be the last level-2 section.';
        }

        return $errors;
    }
}
