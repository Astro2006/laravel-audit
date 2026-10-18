<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Translations;

use Illuminate\Contracts\Support\Arrayable;

/**
 * translations.json: keys missing per locale compared to the default locale.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class TranslationReport implements Arrayable
{
    /**
     * @param  list<string>  $locales
     * @param  list<MissingTranslations>  $missing
     */
    public function __construct(
        public ?string $directory,
        public string $defaultLocale,
        public array $locales,
        public array $missing,
    ) {}

    public function withDirectory(?string $directory): self
    {
        return new self($directory, $this->defaultLocale, $this->locales, $this->missing);
    }

    public function missingCount(): int
    {
        return array_sum(array_map(fn (MissingTranslations $missing): int => $missing->count, $this->missing));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'directory' => $this->directory,
            'default' => $this->defaultLocale,
            'locales' => $this->locales,
            'missing' => array_map(fn (MissingTranslations $missing): array => $missing->toArray(), $this->missing),
        ];
    }
}
