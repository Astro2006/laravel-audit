<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\Severity;

/**
 * Validates a parsed report against the report format. Errors go back to
 * the agent in the repair pass, so they are written for the agent.
 */
final class ReportLinter
{
    private const array REQUIRED_FIELDS = ['severity', 'confidence', 'category', 'check', 'effort', 'locations'];

    private const array BODY_LABELS = ['Evidence', 'Impact', 'Recommendation'];

    public function __construct(
        private readonly string $basePath,
        private readonly EvidenceChecker $evidence = new EvidenceChecker,
    ) {}

    /** @param list<RequiredFinding> $required */
    public function lint(ParsedReport $report, ReportSpec $spec, array $required = []): LintResult
    {
        $errors = [];
        $warnings = [];

        $this->lintSections($report, $spec, $errors);

        // A report without a Findings section (the summary task) has no
        // findings to check; anything it lists there is ignored.
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

            $finding = $this->lintFinding($parsed, $spec, $ids, $errors, $warnings);

            if ($finding === null) {
                continue;
            }

            $reason = $this->rejectionReason($finding, $spec);

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

    /**
     * Findings the harness drops without a repair pass: a file that does not
     * exist, a `missing:` file that does exist, a code quote that is not in
     * the files, or a contradiction with the preflight facts.
     */
    private function rejectionReason(Finding $finding, ReportSpec $spec): ?string
    {
        $contents = [];

        foreach ($finding->locations as $location) {
            if ($location->isMissing()) {
                if (file_exists($this->basePath.'/'.$location->missing)) {
                    return sprintf('`%s` is reported as missing, but it exists.', $location->missing);
                }

                continue;
            }

            if (! $location->isFile()) {
                continue;
            }

            $path = $this->basePath.'/'.$location->file;

            if (! is_file($path)) {
                return sprintf('location `%s` does not exist in the project (possibly hallucinated).', $location->file);
            }

            $contents[] = (string) file_get_contents($path);
        }

        if ($this->evidence->matches($finding->evidence, $contents) === false) {
            return 'the code in Evidence does not appear in '.implode(', ', array_map(
                fn (Location $location): string => '`'.$location->file.'`',
                array_values(array_filter($finding->locations, fn (Location $location): bool => $location->isFile())),
            )).'.';
        }

        return $spec->guard?->reject($finding);
    }

    /** @return list<string> */
    private function summaryWarnings(ParsedReport $report): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $report->summary(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($sentences) > 5 ? [sprintf('Summary has %d sentences; the limit is 5.', count($sentences))] : [];
    }

    /** @param list<string> $errors */
    private function lintSections(ParsedReport $report, ReportSpec $spec, array &$errors): void
    {
        foreach ($spec->sections as $section) {
            if (! $report->hasSection($section)) {
                $errors[] = sprintf('Missing section `## %s`.', $section);
            }
        }

        $last = $report->lastSection();

        if (in_array('Limitations', $spec->sections, true) && $report->hasSection('Limitations') && ($last === null || ! $last->is('Limitations'))) {
            $errors[] = '`## Limitations` must be the last level-2 section.';
        }
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function lintFinding(ParsedFinding $parsed, ReportSpec $spec, array $ids, array &$errors, array &$warnings): ?Finding
    {
        $id = $parsed->id;
        $before = count($errors);

        if (! str_starts_with($id, $spec->idPrefix.'-')) {
            $errors[] = sprintf('%s: wrong ID prefix, expected %s-NNN.', $id, $spec->idPrefix);
        }

        if (! $parsed->hasTable) {
            $errors[] = sprintf('%s: metadata table (`| Field | Value |`) missing directly below the heading.', $id);

            return null;
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if ($parsed->field($field) === null) {
                $errors[] = sprintf('%s: required field "%s" missing.', $id, ucfirst($field));
            }
        }

        $severity = Severity::tryFrom((string) $parsed->field('severity'));
        $confidence = Confidence::tryFrom((string) $parsed->field('confidence'));
        $category = Category::tryFrom((string) $parsed->field('category'));
        $effort = Effort::tryFrom((string) $parsed->field('effort'));
        $check = (string) $parsed->field('check');

        $this->requireEnum($id, 'Severity', $parsed->field('severity'), $severity, Severity::values(), $errors);
        $this->requireEnum($id, 'Confidence', $parsed->field('confidence'), $confidence, Confidence::values(), $errors);
        $this->requireEnum($id, 'Category', $parsed->field('category'), $category, Category::values(), $errors);
        $this->requireEnum($id, 'Effort', $parsed->field('effort'), $effort, Effort::values(), $errors);

        if ($check !== '' && ! array_key_exists($check, $spec->checks)) {
            $errors[] = sprintf(
                '%s: check "%s" is not in your check list (%s). Use one of: %s. Other tasks cover the rest; note observations for other audits under `## Limitations`.',
                $id,
                $check,
                $spec->label,
                implode(', ', array_keys($spec->checks)),
            );
        }

        if ($check === 'other') {
            $warnings[] = sprintf('%s: check "other" — consider a more specific check.', $id);
        }

        $locations = Location::parseList((string) $parsed->field('locations'));

        if ($parsed->field('locations') !== null && $locations === []) {
            $errors[] = sprintf('%s: no parseable location. Use `<path>:<line>`, `route:<METHOD> <uri>`, `package:<vendor>/<name>` or `missing:<path>`.', $id);
        }

        $occurrences = 1;
        $rawOccurrences = $parsed->field('occurrences');

        if ($rawOccurrences !== null) {
            if (preg_match('/^\d+$/', $rawOccurrences) !== 1 || (int) $rawOccurrences < 1) {
                $errors[] = sprintf('%s: Occurrences must be an integer ≥ 1.', $id);
            } else {
                $occurrences = (int) $rawOccurrences;
            }
        }

        foreach (self::BODY_LABELS as $label) {
            if ($parsed->body($label) === '') {
                $errors[] = sprintf('%s: **%s** is missing or empty.', $id, $label);
            }
        }

        if (mb_strlen($parsed->title) > 120) {
            $warnings[] = sprintf('%s: title longer than 120 characters.', $id);
        }

        $related = $this->splitList($parsed->field('related'));

        foreach ($related as $relatedId) {
            if (! in_array($relatedId, $ids, true)) {
                $warnings[] = sprintf('%s: Related "%s" does not exist in this report.', $id, $relatedId);
            }
        }

        foreach ($locations as $location) {
            $this->checkLocation($id, $location, $warnings);
        }

        if (count($errors) > $before || $severity === null || $confidence === null || $category === null || $effort === null) {
            return null;
        }

        return new Finding(
            id: $id,
            title: $parsed->title,
            severity: $severity,
            confidence: $confidence,
            category: $category,
            check: $check,
            effort: $effort,
            locations: $locations,
            occurrences: $occurrences,
            references: $this->splitList($parsed->field('references')),
            related: $related,
            evidence: $parsed->body('Evidence'),
            impact: $parsed->body('Impact'),
            recommendation: $parsed->body('Recommendation'),
        );
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private function requireEnum(string $id, string $field, ?string $value, ?\BackedEnum $case, array $allowed, array &$errors): void
    {
        if ($value !== null && $case === null) {
            $errors[] = sprintf('%s: %s "%s" is not allowed. Use one of: %s.', $id, $field, $value, implode(', ', $allowed));
        }
    }

    /** @param list<string> $warnings */
    private function checkLocation(string $id, Location $location, array &$warnings): void
    {
        if (! $location->isFile()) {
            return;
        }

        $path = $this->basePath.'/'.$location->file;

        if (! is_file($path)) {
            return; // rejected in rejectionReason()
        }

        if ($location->lastLine() !== null) {
            $lineCount = count(file($path) ?: []);

            if ($location->lastLine() > $lineCount) {
                $warnings[] = sprintf('%s: `%s` has %d lines, but the location points to line %d.', $id, $location->file, $lineCount, $location->lastLine());
            }
        }
    }

    /** @return list<string> */
    private function splitList(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = array_map(fn (string $item): string => trim($item, " \t`"), explode(',', $value));

        return array_values(array_filter($items, fn (string $item): bool => $item !== '' && $item !== '-' && strcasecmp($item, 'none') !== 0));
    }
}
