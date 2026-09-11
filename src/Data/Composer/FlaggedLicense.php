<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string> */
final readonly class FlaggedLicense implements Arrayable
{
    public function __construct(
        public string $package,
        public string $license,
        public string $rootLicense,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(strtolower(Read::string($data, 'package')), Read::string($data, 'license'), Read::string($data, 'root_license'));
    }

    /** @return array{package: string, license: string, root_license: string} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'license' => $this->license, 'root_license' => $this->rootLicense];
    }
}
