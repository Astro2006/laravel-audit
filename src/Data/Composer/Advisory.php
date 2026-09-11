<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class Advisory implements Arrayable
{
    /** @param list<string> $via direct dependencies that pull the package in */
    public function __construct(
        public string $package,
        public ?string $installed,
        public ?string $advisory,
        public ?string $cve,
        public ?string $title,
        public ?string $severity,
        public ?string $affected,
        public ?string $link,
        public bool $direct,
        public ?string $section,
        public array $via,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            package: strtolower(Read::string($data, 'package')),
            installed: Read::nullableString($data, 'installed'),
            advisory: Read::nullableString($data, 'advisory'),
            cve: Read::nullableString($data, 'cve'),
            title: Read::nullableString($data, 'title'),
            severity: Read::nullableString($data, 'severity'),
            affected: Read::nullableString($data, 'affected'),
            link: Read::nullableString($data, 'link'),
            direct: Read::bool($data, 'direct'),
            section: Read::nullableString($data, 'section'),
            via: Read::strings($data, 'via'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'installed' => $this->installed,
            'advisory' => $this->advisory,
            'cve' => $this->cve,
            'title' => $this->title,
            'severity' => $this->severity,
            'affected' => $this->affected,
            'link' => $this->link,
            'direct' => $this->direct,
            'section' => $this->section,
            'via' => $this->via,
        ];
    }
}
