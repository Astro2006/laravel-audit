<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string> */
final readonly class FlaggedConstraint implements Arrayable
{
    public function __construct(
        public string $package,
        public string $constraint,
        public string $reason,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(strtolower(Read::string($data, 'package')), Read::string($data, 'constraint'), Read::string($data, 'reason'));
    }

    /** @return array{package: string, constraint: string, reason: string} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'constraint' => $this->constraint, 'reason' => $this->reason];
    }
}
