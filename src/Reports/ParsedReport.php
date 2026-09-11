<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class ParsedReport
{
    /**
     * @param  list<ReportSection>  $sections  level-2 sections in order of appearance
     * @param  list<ParsedFinding>  $findings
     * @param  list<InvalidHeading>  $invalidHeadings  level-3 headings in "Findings" that are not findings
     */
    public function __construct(
        public array $sections,
        public array $findings,
        public array $invalidHeadings,
        public bool $noFindingsMarker,
    ) {}

    /** @return list<string> */
    public function sectionTitles(): array
    {
        return array_map(fn (ReportSection $section): string => $section->title, $this->sections);
    }

    public function hasSection(string $title): bool
    {
        return $this->find($title) !== null;
    }

    public function section(string $title): ?string
    {
        return $this->find($title)?->content();
    }

    public function lastSection(): ?ReportSection
    {
        $sections = $this->sections;

        return $sections === [] ? null : end($sections);
    }

    public function summary(): string
    {
        return trim((string) $this->section('Summary'));
    }

    private function find(string $title): ?ReportSection
    {
        foreach ($this->sections as $section) {
            if ($section->is($title)) {
                return $section;
            }
        }

        return null;
    }
}
