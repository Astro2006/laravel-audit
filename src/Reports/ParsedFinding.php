<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Illuminate\Support\Collection;

final class ParsedFinding
{
    /** @var Collection<string, string> lower-cased field name => raw value */
    private Collection $fields;

    /** @var Collection<string, list<string>> Evidence|Impact|Recommendation => lines */
    private Collection $bodies;

    public bool $hasTable = false;

    public int $endLine;

    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly int $line,
    ) {
        $this->endLine = $line;
        $this->fields = new Collection;
        $this->bodies = new Collection;
    }

    public function setField(string $name, string $value): void
    {
        $this->fields->put(strtolower(trim($name)), trim($value));
        $this->hasTable = true;
    }

    public function field(string $name): ?string
    {
        $value = trim((string) $this->fields->get(strtolower($name), ''));

        return $value === '' ? null : $value;
    }

    public function startBody(string $label): void
    {
        if (! $this->bodies->has($label)) {
            $this->bodies->put($label, []);
        }
    }

    public function addBodyLine(string $label, string $line): void
    {
        $this->bodies->put($label, [...$this->bodies->get($label, []), $line]);
    }

    public function body(string $label): string
    {
        return trim(implode("\n", $this->bodies->get($label, [])));
    }
}
