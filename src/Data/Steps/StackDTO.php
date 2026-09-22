<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class StackDTO implements StepDTO
{
    /** @param list<string> $frontend */
    public function __construct(
        public array $frontend = [],
        public string $testFramework = 'none',
        public ?int $pestVersion = null,
        public bool $browserPlugin = false,
        public bool $boost = false,
        public string $packageManager = 'npm',
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            Read::strings($data, 'frontend'),
            Read::string($data, 'test_framework', 'none'),
            Read::nullableInt($data, 'pest_version'),
            Read::bool($data, 'browser_plugin'),
            Read::bool($data, 'boost'),
            Read::string($data, 'package_manager', 'npm'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'frontend' => $this->frontend,
            'test_framework' => $this->testFramework,
            'pest_version' => $this->pestVersion,
            'browser_plugin' => $this->browserPlugin,
            'boost' => $this->boost,
            'package_manager' => $this->packageManager,
        ];
    }
}
