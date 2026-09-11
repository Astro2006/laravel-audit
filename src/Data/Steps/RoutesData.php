<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepData;
use Astro2006\LaravelAudit\Support\Read;

final readonly class RoutesData implements StepData
{
    public function __construct(
        public int $count = 0,
        public int $flagged = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(Read::int($data, 'count'), Read::int($data, 'flagged'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['count' => $this->count, 'flagged' => $this->flagged, 'file' => 'routes.json', 'facts_file' => 'route-facts.json'];
    }
}
