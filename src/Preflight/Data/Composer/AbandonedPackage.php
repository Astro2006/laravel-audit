<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string|null> */
final readonly class AbandonedPackage implements Arrayable
{
    public function __construct(
        public string $package,
        public ?string $replacement,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(strtolower(Read::string($data, 'package')), Read::nullableString($data, 'replacement'));
    }

    /** @return array{package: string, replacement: string|null} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'replacement' => $this->replacement];
    }
}
