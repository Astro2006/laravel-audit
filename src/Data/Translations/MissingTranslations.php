<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Translations;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class MissingTranslations implements Arrayable
{
    /** @param list<string> $keys at most 50 */
    public function __construct(
        public string $locale,
        public int $count,
        public array $keys,
    ) {}

    /** @return array{locale: string, count: int, keys: list<string>} */
    public function toArray(): array
    {
        return ['locale' => $this->locale, 'count' => $this->count, 'keys' => $this->keys];
    }
}
