<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Reads the report body an agent returns. Only level-2 headings, finding
 * headings and the metadata tables are structure; everything else is free
 * markdown. Lines inside code fences are never treated as structure.
 */
final class ReportParser
{
    private const string FINDING_HEADING = '/^([A-Z]{2,4}-\d{3})\s*[—–-]\s*(.+)$/u';

    private const string BODY_LABEL = '/^\*\*(Evidence|Impact|Recommendation)\*\*\s*:?\s*(.*)$/i';

    public function parse(string $markdown): ParsedReport
    {
        $sections = [];
        $content = [];
        $current = null;

        foreach ($this->lines($markdown) as [$number, $text, $inFence]) {
            if (! $inFence && preg_match('/^##\s+(?!#)(.+?)\s*#*\s*$/', $text, $match) === 1) {
                $current = trim($match[1]);
                $sections[] = $current;
                $content[$current] = [];

                continue;
            }

            if ($current !== null) {
                $content[$current][] = [$number, $text, $inFence];
            }
        }

        $findingsTitle = null;

        foreach ($sections as $section) {
            if (strcasecmp($section, 'Findings') === 0) {
                $findingsTitle = $section;
            }
        }

        [$findings, $invalidHeadings, $noFindings] = $findingsTitle === null
            ? [[], [], false]
            : $this->parseFindings($content[$findingsTitle]);

        return new ParsedReport(
            sections: $sections,
            sectionContent: array_map(
                fn (array $lines): string => implode("\n", array_column($lines, 1)),
                $content,
            ),
            findings: $findings,
            invalidHeadings: $invalidHeadings,
            noFindingsMarker: $noFindings,
        );
    }

    /**
     * @param  list<array{int, string, bool}>  $lines
     * @return array{list<ParsedFinding>, list<array{line: int, text: string}>, bool}
     */
    private function parseFindings(array $lines): array
    {
        $findings = [];
        $invalid = [];
        $noFindings = false;
        $finding = null;
        $label = null;

        foreach ($lines as [$number, $text, $inFence]) {
            if (! $inFence && preg_match('/^###\s+(?!#)(.*)$/', $text, $match) === 1) {
                $label = null;
                $heading = trim($match[1]);

                if (preg_match(self::FINDING_HEADING, $heading, $parts) === 1) {
                    $finding = new ParsedFinding($parts[1], trim($parts[2]), $number);
                    $findings[] = $finding;
                } else {
                    $invalid[] = ['line' => $number, 'text' => $text];
                    $finding = null;
                }

                continue;
            }

            if ($finding === null) {
                if (strcasecmp(trim($text), 'No findings.') === 0) {
                    $noFindings = true;
                }

                continue;
            }

            if (! $inFence && preg_match('/^\s*-{3,}\s*$/', $text) === 1) {
                $finding = null;
                $label = null;

                continue;
            }

            if (! $inFence && $label === null && preg_match('/^\|\s*([^|]+?)\s*\|(.*)\|\s*$/', $text, $cells) === 1) {
                $name = strtolower(trim($cells[1]));
                $value = trim($cells[2]);

                if ($name === 'field' || preg_match('/^:?-+:?$/', $name) === 1) {
                    $finding->hasTable = true;

                    continue;
                }

                $finding->fields[$name] = $value;
                $finding->hasTable = true;

                continue;
            }

            if (! $inFence && preg_match(self::BODY_LABEL, trim($text), $match) === 1) {
                $label = ucfirst(strtolower($match[1]));
                $finding->bodies[$label] ??= [];

                if (trim($match[2]) !== '') {
                    $finding->bodies[$label][] = trim($match[2]);
                }

                continue;
            }

            if ($label !== null) {
                $finding->bodies[$label][] = $text;
            }
        }

        return [$findings, $invalid, $noFindings];
    }

    /**
     * @return list<array{int, string, bool}> line number, text, inside a code fence
     */
    private function lines(string $markdown): array
    {
        $result = [];
        $fence = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $index => $text) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $text, $match) === 1) {
                $marker = $match[1];

                if ($fence === null) {
                    $fence = $marker;
                    $result[] = [$index + 1, $text, true];

                    continue;
                }

                if ($marker[0] === $fence[0] && strlen($marker) >= strlen($fence) && trim($text) === $marker) {
                    $fence = null;
                    $result[] = [$index + 1, $text, true];

                    continue;
                }
            }

            $result[] = [$index + 1, $text, $fence !== null];
        }

        return $result;
    }
}
