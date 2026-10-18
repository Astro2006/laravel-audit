<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class CandidatesDTO implements StepDTO
{
    public function __construct(
        public int $patterns = 0,
        public int $hits = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(Read::int($data, 'patterns'), Read::int($data, 'hits'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['patterns' => $this->patterns, 'hits' => $this->hits, 'file' => 'code/candidates.json'];
    }
}
