<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\Severity;

/**
 * Validates one finding: ID, metadata table, allowed values, locations and
 * body. Errors go back to the agent in the repair pass.
 */
final class FindingLinter
{
    private const array REQUIRED_FIELDS = ['severity', 'confidence', 'category', 'check', 'effort', 'locations'];

    private const array BODY_LABELS = ['Evidence', 'Impact', 'Recommendation'];

    public function __construct(private readonly string $basePath) {}

    /**
     * The finding when it is valid, and the errors and warnings about it.
     *
     * @param  list<string>  $ids  every finding ID of the report, for Related
     * @return array{Finding|null, list<string>, list<string>}
     */
    public function lint(ParsedFinding $parsed, ReportSpec $spec, array $ids): array
    {
        $id = $parsed->id;
        $errors = [];
        $warnings = [];

        if (! str_starts_with($id, $spec->idPrefix.'-')) {
            $errors[] = sprintf('%s: wrong ID prefix, expected %s-NNN.', $id, $spec->idPrefix);
        }

        if (! $parsed->hasTable) {
            $errors[] = sprintf('%s: metadata table (`| Field | Value |`) missing directly below the heading.', $id);

            return [null, $errors, $warnings];
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

        if ($errors !== [] || $severity === null || $confidence === null || $category === null || $effort === null) {
            return [null, $errors, $warnings];
        }

        return [new Finding(
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
        ), $errors, $warnings];
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
            return; // FindingRejector drops the finding
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
