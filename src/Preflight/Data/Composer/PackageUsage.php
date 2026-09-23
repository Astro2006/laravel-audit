<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Where the app uses one direct Composer package.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PackageUsage implements Arrayable
{
    /**
     * @param  'require'|'require-dev'  $section
     * @param  list<string>  $namespaces
     * @param  list<string>  $topFiles
     * @param  list<string>  $providers
     * @param  list<string>  $aliases
     * @param  list<string>  $binaries
     */
    public function __construct(
        public string $name,
        public string $section,
        public ?string $type,
        public array $namespaces,
        public int $prodRefs,
        public int $testRefs,
        public array $topFiles,
        public array $providers,
        public array $aliases,
        public bool $providerDiscovered,
        public array $binaries,
        public bool $binaryUsed,
        public bool $hasFilesAutoload,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            section: Read::string($data, 'section') === 'require-dev' ? 'require-dev' : 'require',
            type: Read::nullableString($data, 'type'),
            namespaces: Read::strings($data, 'namespaces'),
            prodRefs: Read::int($data, 'prod_refs'),
            testRefs: Read::int($data, 'test_refs'),
            topFiles: Read::strings($data, 'top_files'),
            providers: Read::strings($data, 'providers'),
            aliases: Read::strings($data, 'aliases'),
            providerDiscovered: Read::bool($data, 'provider_discovered'),
            binaries: Read::strings($data, 'binaries'),
            binaryUsed: Read::bool($data, 'binary_used'),
            hasFilesAutoload: Read::bool($data, 'has_files_autoload'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'type' => $this->type,
            'namespaces' => $this->namespaces,
            'prod_refs' => $this->prodRefs,
            'test_refs' => $this->testRefs,
            'top_files' => $this->topFiles,
            'providers' => $this->providers,
            'aliases' => $this->aliases,
            'provider_discovered' => $this->providerDiscovered,
            'binaries' => $this->binaries,
            'binary_used' => $this->binaryUsed,
            'has_files_autoload' => $this->hasFilesAutoload,
        ];
    }
}
