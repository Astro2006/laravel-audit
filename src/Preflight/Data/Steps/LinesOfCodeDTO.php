<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class LinesOfCodeDTO implements StepDTO
{
    public function __construct(
        public int $loc = 0,
        public int $files = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(Read::int($data, 'loc'), Read::int($data, 'files'));
    }

    /** @return array{loc: int, files: int} */
    public function toArray(): array
    {
        return ['loc' => $this->loc, 'files' => $this->files];
    }
}
