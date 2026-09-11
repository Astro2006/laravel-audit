<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Fixes trivial format slips without asking the agent again: heading
 * separators, ID padding, enum casing, decorated values and body labels.
 */
final class ReportNormalizer
{
    private const array LOWERCASE_FIELDS = ['severity', 'confidence', 'category', 'check'];

    private const array CANONICAL_FIELDS = [
        'severity' => 'Severity',
        'confidence' => 'Confidence',
        'category' => 'Category',
        'check' => 'Check',
        'effort' => 'Effort',
        'locations' => 'Locations',
        'location' => 'Locations',
        'occurrences' => 'Occurrences',
        'references' => 'References',
        'related' => 'Related',
    ];

    public function normalize(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $fence = null;
        $output = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $match) === 1) {
                if ($fence === null) {
                    $fence = $match[1];
                } elseif ($match[1][0] === $fence[0] && trim($line) === $match[1]) {
                    $fence = null;
                }

                $output[] = rtrim($line);

                continue;
            }

            if ($fence !== null) {
                $output[] = $line;

                continue;
            }

            $output[] = $this->normalizeLine(rtrim($line));
        }

        return implode("\n", $output);
    }

    private function normalizeLine(string $line): string
    {
        if (preg_match('/^###\s+([A-Z]{2,4})-(\d{1,3})\s*(?:—|–|-|:)\s*(.+)$/u', $line, $match) === 1) {
            return sprintf('### %s-%03d — %s', $match[1], (int) $match[2], trim($match[3]));
        }

        if (preg_match('/^\|\s*([A-Za-z]+)\s*\|(.*)\|\s*$/', $line, $match) === 1) {
            $field = strtolower($match[1]);

            if (isset(self::CANONICAL_FIELDS[$field])) {
                return '| '.self::CANONICAL_FIELDS[$field].' | '.$this->normalizeValue($field, trim($match[2])).' |';
            }

            return $line;
        }

        if (preg_match('/^\*\*\s*(Evidence|Impact|Recommendation)\s*:?\s*\*\*\s*:?\s*(.*)$/i', trim($line), $match) === 1) {
            $label = '**'.ucfirst(strtolower($match[1])).'**';

            return trim($match[2]) === '' ? $label : $label."\n".trim($match[2]);
        }

        return $line;
    }

    private function normalizeValue(string $field, string $value): string
    {
        if ($field === 'locations' || $field === 'location') {
            return $value;
        }

        $plain = trim($value, " *`_");

        if (in_array($field, self::LOWERCASE_FIELDS, true)) {
            return strtolower($plain);
        }

        if ($field === 'effort') {
            return match (strtolower($plain)) {
                's', 'small' => 'S',
                'm', 'medium' => 'M',
                'l', 'large' => 'L',
                default => $plain,
            };
        }

        return $plain;
    }
}
