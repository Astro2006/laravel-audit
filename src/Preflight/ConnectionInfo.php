<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

/**
 * A database connection as resolved for the app or for the test run.
 */
final readonly class ConnectionInfo
{
    public function __construct(
        public string $driver,
        public string $host,
        public string $database,
        public string $label,
    ) {}

    public function isInMemorySqlite(): bool
    {
        return $this->driver === 'sqlite' && $this->database === ':memory:';
    }
}
