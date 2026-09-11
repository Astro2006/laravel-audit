<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Support\Regex;

/**
 * Fixes trivial format slips without asking the agent again: heading
 * separators, ID padding, enum casing, decorated values and body labels.
 */
final class ReportNormalizer
{
    public function normalize(string $markdown): string
    {
        $fence = null;
        $output = [];

        foreach (Regex::lines($markdown) as $line) {
            $fenceMatch = Regex::groups('/^\s{0,3}(`{3,}|~{3,})/', $line);

            if ($fenceMatch !== null) {
                [$marker] = $fenceMatch;

                if ($fence === null) {
                    $fence = $marker;
                } elseif (str_starts_with($marker, substr($fence, 0, 1)) && trim($line) === $marker) {
                    $fence = null;
                }

                $output[] = rtrim($line);

                continue;
            }

            $output[] = $fence === null ? $this->normalizeLine(rtrim($line)) : $line;
        }

        return implode("\n", $output);
    }

    private function normalizeLine(string $line): string
    {
        $heading = Regex::groups('/^###\s+([A-Z]{2,4})-(\d{1,3})\s*(?:—|–|-|:)\s*(.+)$/u', $line);

        if ($heading !== null) {
            [$prefix, $number, $title] = $heading;

            return sprintf('### %s-%03d — %s', $prefix, (int) $number, trim($title));
        }

        $row = Regex::groups('/^\|\s*([A-Za-z]+)\s*\|(.*)\|\s*$/', $line);

        if ($row !== null) {
            [$field, $value] = $row;
            $canonical = $this->canonicalField($field);

            return $canonical === null ? $line : '| '.$canonical.' | '.$this->normalizeValue($canonical, trim($value)).' |';
        }

        $label = Regex::groups('/^\*\*\s*(Evidence|Impact|Recommendation)\s*:?\s*\*\*\s*:?\s*(.*)$/i', trim($line));

        if ($label !== null) {
            [$name, $rest] = $label;
            $bold = '**'.ucfirst(strtolower($name)).'**';

            return trim($rest) === '' ? $bold : $bold."\n".trim($rest);
        }

        return $line;
    }

    private function canonicalField(string $field): ?string
    {
        return match (strtolower($field)) {
            'severity' => 'Severity',
            'confidence' => 'Confidence',
            'category' => 'Category',
            'check' => 'Check',
            'effort' => 'Effort',
            'locations', 'location' => 'Locations',
            'occurrences' => 'Occurrences',
            'references' => 'References',
            'related' => 'Related',
            default => null,
        };
    }

    private function normalizeValue(string $field, string $value): string
    {
        $plain = trim($value, ' *`_');

        return match ($field) {
            'Locations' => $value,
            'Severity', 'Confidence', 'Category', 'Check' => strtolower($plain),
            'Effort' => match (strtolower($plain)) {
                's', 'small' => 'S',
                'm', 'medium' => 'M',
                'l', 'large' => 'L',
                default => $plain,
            },
            default => $plain,
        };
    }
}
