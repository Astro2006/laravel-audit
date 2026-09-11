<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, string> */
final readonly class SkippedTest implements Arrayable
{
    public function __construct(
        public string $test,
        public string $reason,
        public string $file,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Read::string($data, 'test'), Read::string($data, 'reason'), Read::string($data, 'file'));
    }

    /** @return array{test: string, reason: string, file: string} */
    public function toArray(): array
    {
        return ['test' => $this->test, 'reason' => $this->reason, 'file' => $this->file];
    }
}
