<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

final readonly class DatabaseGuardResult
{
    public function __construct(
        public bool $safe,
        public string $testDatabase,
        public string $appDatabase,
    ) {}

    /** @return array{safe: bool, test: string, app: string} */
    public function toArray(): array
    {
        return ['safe' => $this->safe, 'test' => $this->testDatabase, 'app' => $this->appDatabase];
    }
}
