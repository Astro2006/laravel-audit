<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

final readonly class TestFailure
{
    public function __construct(
        public string $type,
        public string $message,
        public string $test,
        public string $file,
    ) {}

    public function groupKey(): string
    {
        return $this->type.'|'.$this->message;
    }
}
