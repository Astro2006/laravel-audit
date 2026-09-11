<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Result of `composer validate`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ValidateResult implements Arrayable
{
    public function __construct(
        public bool $ok,
        public bool $lockInSync,
        public string $output,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Read::bool($data, 'ok', true), Read::bool($data, 'lock_in_sync', true), Read::string($data, 'output'));
    }

    /** @return array{ok: bool, lock_in_sync: bool, output: string} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'lock_in_sync' => $this->lockInSync, 'output' => $this->output];
    }
}
