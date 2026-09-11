<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class ParsedReport
{
    /**
     * @param  list<string>  $sections  level-2 headings in order of appearance
     * @param  array<string, string>  $sectionContent  heading => raw content
     * @param  list<ParsedFinding>  $findings
     * @param  list<array{line: int, text: string}>  $invalidHeadings  level-3 headings in "Findings" that are not findings
     */
    public function __construct(
        public array $sections,
        public array $sectionContent,
        public array $findings,
        public array $invalidHeadings,
        public bool $noFindingsMarker,
    ) {}

    public function hasSection(string $title): bool
    {
        return $this->sectionTitle($title) !== null;
    }

    public function section(string $title): ?string
    {
        $match = $this->sectionTitle($title);

        return $match === null ? null : $this->sectionContent[$match];
    }

    public function summary(): string
    {
        return trim((string) $this->section('Summary'));
    }

    private function sectionTitle(string $title): ?string
    {
        foreach ($this->sections as $section) {
            if (strcasecmp($section, $title) === 0) {
                return $section;
            }
        }

        return null;
    }
}
