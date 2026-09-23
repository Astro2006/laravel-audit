<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Tests;

use Illuminate\Contracts\Support\Arrayable;

/**
 * coverage-by-class.json: line coverage per class in app/, lowest first.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CoverageReport implements Arrayable
{
    /** @param list<ClassCoverage> $classes */
    public function __construct(
        public ?float $linesPct,
        public array $classes,
    ) {}

    /** @return array{lines_pct: float|null, files: int, classes: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'lines_pct' => $this->linesPct,
            'files' => count($this->classes),
            'classes' => array_map(fn (ClassCoverage $class): array => $class->toArray(), $this->classes),
        ];
    }
}
