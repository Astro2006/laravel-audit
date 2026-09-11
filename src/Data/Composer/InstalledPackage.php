<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;

/**
 * One package from vendor/composer/installed.json.
 */
final readonly class InstalledPackage
{
    /**
     * @param  list<string>  $namespaces  PSR-4 and PSR-0 prefixes, without trailing backslash
     * @param  list<string>  $psr4
     * @param  list<string>  $providers
     * @param  list<string>  $aliases
     * @param  list<string>  $binaries  file names only
     */
    public function __construct(
        public string $name,
        public ?string $type,
        public array $namespaces,
        public array $psr4,
        public array $providers,
        public array $aliases,
        public array $binaries,
        public bool $hasFilesAutoload,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $psr4 = array_map(fn (string $namespace): string => trim($namespace, '\\'), Read::keys($data, 'autoload.psr-4'));
        $psr0 = array_map(fn (string $namespace): string => trim($namespace, '\\'), Read::keys($data, 'autoload.psr-0'));

        return new self(
            name: strtolower(Read::string($data, 'name')),
            type: Read::nullableString($data, 'type'),
            namespaces: array_values(array_filter([...$psr4, ...$psr0])),
            psr4: Read::keys($data, 'autoload.psr-4'),
            providers: Read::strings($data, 'extra.laravel.providers'),
            aliases: Read::keys($data, 'extra.laravel.aliases'),
            binaries: array_map('basename', Read::strings($data, 'bin')),
            hasFilesAutoload: Read::array($data, 'autoload.files') !== [],
        );
    }

    /**
     * @param  array<mixed>  $installedJson  decoded installed.json (Composer 1 or 2 format)
     * @return list<self>
     */
    public static function listFromJson(array $installedJson): array
    {
        $packages = array_key_exists('packages', $installedJson) ? Read::arrays($installedJson, 'packages') : array_values(array_filter($installedJson, 'is_array'));

        return array_values(array_filter(
            array_map(self::fromArray(...), $packages),
            fn (self $package): bool => $package->name !== '',
        ));
    }
}
