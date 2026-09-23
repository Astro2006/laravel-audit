<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Composer;

use Illuminate\Contracts\Support\Arrayable;

/**
 * usage-map.json.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class UsageMap implements Arrayable
{
    /**
     * @param  list<PackageUsage>  $packages
     * @param  list<TransitiveUsage>  $transitive
     * @param  list<string>  $limitations
     */
    public function __construct(
        public array $packages,
        public array $transitive,
        public array $limitations,
    ) {}

    public function package(string $name): ?PackageUsage
    {
        foreach ($this->packages as $package) {
            if ($package->name === $name) {
                return $package;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'packages' => collect($this->packages)->mapWithKeys(fn (PackageUsage $package): array => [$package->name => $package->toArray()])->all(),
            'transitive_usage' => collect($this->transitive)->mapWithKeys(fn (TransitiveUsage $usage): array => [$usage->name => $usage->toArray()])->all(),
            'limitations' => $this->limitations,
        ];
    }
}
