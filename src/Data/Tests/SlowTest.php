<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string|float> */
final readonly class SlowTest implements Arrayable
{
    public function __construct(
        public string $test,
        public float $seconds,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Read::string($data, 'test'), Read::float($data, 'seconds'));
    }

    /** @return array{test: string, seconds: float} */
    public function toArray(): array
    {
        return ['test' => $this->test, 'seconds' => $this->seconds];
    }
}
