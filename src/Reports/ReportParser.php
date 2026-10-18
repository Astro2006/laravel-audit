<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Support\Regex;

final class ReportParser
{
    private const string FINDING_HEADING = '/^([A-Z]{2,4}-\d{3})\s*[—–-]\s*(.+)$/u';

    private const string BODY_LABEL = '/^\*\*(Evidence|Impact|Recommendation)\*\*\s*:?\s*(.*)$/i';

    private const string TABLE_ROW = '/^\|\s*([^|]+?)\s*\|(.*)\|\s*$/';

    public function parse(string $markdown): ParsedReport
    {
        $sections = [];
        $current = null;

        foreach ($this->lines($markdown) as $line) {
            $heading = $line->inFence ? null : Regex::groups('/^##\s+(?!#)(.+?)\s*#*\s*$/', $line->text);

            if ($heading !== null) {
                [$title] = $heading;
                $current = new ReportSection(trim($title));
                $sections[] = $current;

                continue;
            }

            $current?->add($line);
        }

        $findingsSection = null;

        foreach ($sections as $section) {
            if ($section->is('Findings')) {
                $findingsSection = $section;
            }
        }

        [$findings, $invalidHeadings, $noFindings] = $findingsSection === null
            ? [[], [], false]
            : $this->parseFindings($findingsSection->lines);

        return new ParsedReport($sections, $findings, $invalidHeadings, $noFindings);
    }

    /**
     * @param  list<ReportLine>  $lines
     * @return array{list<ParsedFinding>, list<InvalidHeading>, bool}
     */
    private function parseFindings(array $lines): array
    {
        $findings = [];
        $invalid = [];
        $noFindings = false;
        $finding = null;
        $label = null;

        foreach ($lines as $line) {
            $text = $line->text;

            $heading = $line->inFence ? null : Regex::groups('/^###\s+(?!#)(.*)$/', $text);

            if ($heading !== null) {
                $label = null;
                $finding = null;
                [$headingText] = $heading;
                $parts = Regex::groups(self::FINDING_HEADING, trim($headingText));

                if ($parts !== null) {
                    [$id, $title] = $parts;
                    $finding = new ParsedFinding($id, trim($title), $line->number);
                    $findings[] = $finding;
                } else {
                    $invalid[] = new InvalidHeading($line->number, $text);
                }

                continue;
            }

            if ($finding === null) {
                $noFindings = $noFindings || strcasecmp(trim($text), 'No findings.') === 0;

                continue;
            }

            $finding->endLine = $line->number;

            if (! $line->inFence && preg_match('/^\s*-{3,}\s*$/', $text) === 1) {
                $finding = null;
                $label = null;

                continue;
            }

            $cells = $line->inFence || $label !== null ? null : Regex::groups(self::TABLE_ROW, $text);

            if ($cells !== null) {
                [$field, $value] = $cells;
                $name = strtolower(trim($field));

                if ($name === 'field' || preg_match('/^:?-+:?$/', $name) === 1) {
                    $finding->hasTable = true;
                } else {
                    $finding->setField($name, $value);
                }

                continue;
            }

            $body = $line->inFence ? null : Regex::groups(self::BODY_LABEL, trim($text));

            if ($body !== null) {
                [$bodyLabel, $rest] = $body;
                $label = ucfirst(strtolower($bodyLabel));
                $finding->startBody($label);

                if (trim($rest) !== '') {
                    $finding->addBodyLine($label, trim($rest));
                }

                continue;
            }

            if ($label !== null) {
                $finding->addBodyLine($label, $text);
            }
        }

        return [$findings, $invalid, $noFindings];
    }

    /** @return list<ReportLine> */
    private function lines(string $markdown): array
    {
        $result = [];
        $fence = null;

        foreach (Regex::lines($markdown) as $index => $text) {
            $fenceMatch = Regex::groups('/^\s{0,3}(`{3,}|~{3,})/', $text);

            if ($fenceMatch !== null) {
                [$marker] = $fenceMatch;

                if ($fence === null) {
                    $fence = $marker;
                    $result[] = new ReportLine($index + 1, $text, true);

                    continue;
                }

                if (str_starts_with($marker, substr($fence, 0, 1)) && strlen($marker) >= strlen($fence) && trim($text) === $marker) {
                    $fence = null;
                    $result[] = new ReportLine($index + 1, $text, true);

                    continue;
                }
            }

            $result[] = new ReportLine($index + 1, $text, $fence !== null);
        }

        return $result;
    }
}
