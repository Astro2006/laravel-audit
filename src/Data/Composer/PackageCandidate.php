<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A package that may be misplaced, unused or a shadow dependency. The
 * dependencies agent confirms or rejects it.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PackageCandidate implements Arrayable
{
    /** @param list<string> $topFiles */
    public function __construct(
        public string $package,
        public ?string $section = null,
        public int $prodRefs = 0,
        public int $testRefs = 0,
        public array $topFiles = [],
        public ?string $suggestion = null,
        public bool $hasFilesAutoload = false,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            package: strtolower(Read::string($data, 'package')),
            section: Read::nullableString($data, 'section'),
            prodRefs: Read::int($data, 'prod_refs'),
            testRefs: Read::int($data, 'test_refs'),
            topFiles: Read::strings($data, 'top_files'),
            suggestion: Read::nullableString($data, 'suggestion'),
            hasFilesAutoload: Read::bool($data, 'has_files_autoload'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'package' => $this->package,
            'section' => $this->section,
            'prod_refs' => $this->prodRefs,
            'test_refs' => $this->testRefs,
            'top_files' => $this->topFiles === [] ? null : $this->topFiles,
            'suggestion' => $this->suggestion,
            'has_files_autoload' => $this->hasFilesAutoload ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
