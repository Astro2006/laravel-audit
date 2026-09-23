<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class TranslationsDTO implements StepDTO
{
    /** @param list<string> $locales */
    public function __construct(
        public array $locales = [],
        public int $missing = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(Read::strings($data, 'locales'), Read::int($data, 'missing'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['locales' => $this->locales, 'missing' => $this->missing, 'file' => 'translations.json'];
    }
}
