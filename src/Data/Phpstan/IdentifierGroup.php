<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Phpstan;

use Illuminate\Contracts\Support\Arrayable;

/**
 * All PHPStan errors of one identifier.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class IdentifierGroup implements Arrayable
{
    /**
     * @param  list<FileCount>  $topFiles  at most five, most errors first
     * @param  list<PhpstanMessage>  $examples  at most three
     */
    public function __construct(
        public string $identifier,
        public int $count,
        public int $fileCount,
        public array $topFiles,
        public array $examples,
        public bool $baselined = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'identifier' => $this->identifier,
            'count' => $this->count,
            'file_count' => $this->fileCount,
            'top_files' => array_map(fn (FileCount $file): array => $file->toArray(), $this->topFiles),
            'examples' => $this->baselined ? null : array_map(fn (PhpstanMessage $message): array => $message->toArray(), $this->examples),
            'baselined' => $this->baselined ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
