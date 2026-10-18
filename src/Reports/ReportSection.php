<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final class ReportSection
{
    /** @var list<ReportLine> */
    public array $lines = [];

    public function __construct(public readonly string $title) {}

    public function add(ReportLine $line): void
    {
        $this->lines[] = $line;
    }

    public function content(): string
    {
        return implode("\n", array_map(fn (ReportLine $line): string => $line->text, $this->lines));
    }

    public function is(string $title): bool
    {
        return strcasecmp($this->title, $title) === 0;
    }
}
