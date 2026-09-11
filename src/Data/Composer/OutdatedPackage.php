<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string> */
final readonly class OutdatedPackage implements Arrayable
{
    public function __construct(
        public string $package,
        public string $installed,
        public string $latest,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(strtolower(Read::string($data, 'package')), Read::string($data, 'installed'), Read::string($data, 'latest'));
    }

    /** @return array{package: string, installed: string, latest: string} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'installed' => $this->installed, 'latest' => $this->latest];
    }
}
