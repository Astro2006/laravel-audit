<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Phpstan;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string|int> */
final readonly class FileCount implements Arrayable
{
    public function __construct(
        public string $file,
        public int $count,
    ) {}

    /** @return array{file: string, count: int} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'count' => $this->count];
    }
}
