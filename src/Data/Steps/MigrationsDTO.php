<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class MigrationsDTO implements StepDTO
{
    public function __construct(
        public int $migrations = 0,
        public int $tables = 0,
        public int $flagged = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(Read::int($data, 'migrations'), Read::int($data, 'tables'), Read::int($data, 'flagged'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['migrations' => $this->migrations, 'tables' => $this->tables, 'flagged' => $this->flagged, 'file' => 'database/schema.json'];
    }
}
