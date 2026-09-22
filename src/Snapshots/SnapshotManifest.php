<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;

/**
 * What a snapshot shows in the prompt: a title, metrics and a table with one
 * row per ID. Rows beyond the limit are only listed in _all.md.
 */
final readonly class SnapshotManifest
{
    public const string ALL_FILE = '_all';

    /**
     * @param  list<string>  $metrics  e.g. "Failed 8"
     * @param  list<string>  $columns  table headers after the ID column
     * @param  list<SnapshotRow>  $rows
     * @param  list<SnapshotFile>  $files
     */
    public function __construct(
        public SnapshotType $type,
        public string $title,
        public array $metrics,
        public array $columns = [],
        public array $rows = [],
        public array $files = [],
    ) {}

    public function id(int $index): string
    {
        return sprintf('%s-%02d', $this->type->prefix(), $index + 1);
    }

    /**
     * @param  string  $detailsDir  where the agent finds the detail files, relative to the project
     */
    public function render(string $detailsDir, int $maxRows): string
    {
        $lines = ['### '.$this->title];

        if ($this->metrics !== []) {
            $lines[] = implode(' · ', $this->metrics);
        }

        if ($this->rows !== []) {
            $lines[] = '';
            array_push($lines, ...$this->table(max(1, $maxRows)));

            $more = count($this->rows) - max(1, $maxRows);

            if ($more > 0) {
                $lines[] = sprintf('… %d more rows: `%s/%s.md`', $more, $detailsDir, self::ALL_FILE);
            }
        }

        $pointers = [];

        if (array_filter($this->rows, fn (SnapshotRow $row): bool => $row->details !== null) !== []) {
            $pointers[] = sprintf('details per ID: `%s/<ID>.md`', $detailsDir);
        }

        foreach ($this->files as $file) {
            $pointers[] = sprintf('%s: `%s/%s.md`', $file->label, $detailsDir, $file->name);
        }

        if ($pointers !== []) {
            $lines[] = '';
            $lines[] = ucfirst(implode(' · ', $pointers));
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    public function table(?int $limit = null): array
    {
        $lines = [
            '| '.implode(' | ', ['ID', ...$this->columns]).' |',
            '|'.str_repeat('---|', count($this->columns) + 1),
        ];

        foreach (array_slice($this->rows, 0, $limit) as $index => $row) {
            $lines[] = '| '.implode(' | ', array_map(self::cell(...), [$this->id($index), ...$row->cells])).' |';
        }

        return $lines;
    }

    private static function cell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], trim($value));
    }
}
