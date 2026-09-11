<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class ClassCoverage implements Arrayable
{
    /** @param list<string> $uncoveredMethods */
    public function __construct(
        public ?string $class,
        public string $file,
        public float $linesPct,
        public int $statements,
        public array $uncoveredMethods,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Read::nullableString($data, 'class'),
            Read::string($data, 'file'),
            Read::float($data, 'lines_pct'),
            Read::int($data, 'statements'),
            Read::strings($data, 'uncovered_methods'),
        );
    }

    /** @return array{class: string|null, file: string, lines_pct: float, statements: int, uncovered_methods: list<string>} */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'file' => $this->file,
            'lines_pct' => $this->linesPct,
            'statements' => $this->statements,
            'uncovered_methods' => $this->uncoveredMethods,
        ];
    }
}
