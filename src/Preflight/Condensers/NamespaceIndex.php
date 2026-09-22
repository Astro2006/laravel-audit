<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Composer\InstalledPackage;
use Illuminate\Support\Collection;

/**
 * Maps class names to the installed package that autoloads them.
 */
final class NamespaceIndex
{
    /** @var Collection<string, string> namespace prefix (lower case, trailing backslash) => package, longest first */
    private Collection $namespaces;

    /** @var Collection<string, string|null> */
    private Collection $resolved;

    /** @param list<InstalledPackage> $installed */
    public function __construct(array $installed)
    {
        $this->resolved = new Collection;
        $namespaces = new Collection;

        foreach ($installed as $package) {
            foreach ($package->namespaces as $namespace) {
                $namespaces->put(strtolower($namespace).'\\', $package->name);
            }
        }

        $this->namespaces = $namespaces->sortKeysUsing(fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /** The package whose namespace the class belongs to. */
    public function packageFor(string $name): ?string
    {
        $key = strtolower($name).'\\';

        if (! $this->resolved->has($key)) {
            $this->resolved->put($key, $this->namespaces->first(
                fn (string $package, string $prefix): bool => str_starts_with($key, $prefix),
            ));
        }

        return $this->resolved->get($key);
    }
}
