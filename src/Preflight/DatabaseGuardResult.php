<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class DatabaseGuardResult implements Arrayable
{
    public function __construct(
        public bool $safe,
        public string $testDatabase,
        public string $appDatabase,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Read::bool($data, 'safe'), Read::string($data, 'test'), Read::string($data, 'app'));
    }

    /** @return array{safe: bool, test: string, app: string} */
    public function toArray(): array
    {
        return ['safe' => $this->safe, 'test' => $this->testDatabase, 'app' => $this->appDatabase];
    }
}
