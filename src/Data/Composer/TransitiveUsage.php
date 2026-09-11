<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Production code using a package that is only installed transitively.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class TransitiveUsage implements Arrayable
{
    /** @param list<string> $topFiles */
    public function __construct(
        public string $name,
        public int $prodRefs,
        public array $topFiles,
    ) {}

    /** @return array{prod_refs: int, top_files: list<string>} */
    public function toArray(): array
    {
        return ['prod_refs' => $this->prodRefs, 'top_files' => $this->topFiles];
    }
}
