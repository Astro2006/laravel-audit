<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Run;

use Astro2006\LaravelAudit\Support\Read;

/**
 * A finding as stored in run.json.
 */
final readonly class FindingRecord
{
    /**
     * @param  list<string>  $targets  location targets without line numbers
     * @param  list<string>  $locations
     */
    public function __construct(
        public string $key,
        public string $id,
        public string $title,
        public string $check,
        public string $severity,
        public array $targets,
        public array $locations,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Read::string($data, 'key'),
            Read::string($data, 'id'),
            Read::string($data, 'title'),
            Read::string($data, 'check'),
            Read::string($data, 'severity'),
            Read::strings($data, 'targets'),
            Read::strings($data, 'locations'),
        );
    }
}
