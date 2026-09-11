<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * A finding as it appears in the report, before validation.
 */
final class ParsedFinding
{
    /** @var array<string, string> lower-cased field name => raw value */
    public array $fields = [];

    /** @var array<string, list<string>> Evidence|Impact|Recommendation => lines */
    public array $bodies = [];

    public bool $hasTable = false;

    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly int $line,
    ) {}

    public function field(string $name): ?string
    {
        $value = $this->fields[strtolower($name)] ?? null;

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    public function body(string $label): string
    {
        return trim(implode("\n", $this->bodies[$label] ?? []));
    }
}
