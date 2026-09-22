<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Composer\ClassReference;
use Astro2006\LaravelAudit\Data\Composer\InstalledPackage;
use Astro2006\LaravelAudit\Data\Composer\PackageUsage;
use Astro2006\LaravelAudit\Data\Composer\TransitiveUsage;
use Astro2006\LaravelAudit\Data\Composer\UsageMap;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Collection;

final class UsageMapBuilder
{
    private const array PRODUCTION_DIRS = ['app', 'config', 'database', 'routes', 'bootstrap', 'resources/views'];

    private const string TEST_DIR = 'tests';

    private NamespaceIndex $namespaces;

    public function __construct(private readonly ProjectInspector $project)
    {
        $this->namespaces = new NamespaceIndex([]);
    }

    public function build(): UsageMap
    {
        $installed = $this->project->composer()->installedPackages();
        $this->namespaces = new NamespaceIndex($installed);

        $references = new Collection($this->references());
        $direct = $this->project->composer()->directPackages();
        $dontDiscover = array_map('strtolower', Read::strings($this->project->composer()->composerJson(), 'extra.laravel.dont-discover'));
        $binaryUsage = new BinaryUsage($this->project);

        $packages = [];

        foreach ($direct as $name => $section) {
            $package = $this->find($installed, $name);
            $own = $references->filter(fn (ClassReference $reference): bool => $reference->package === $name);
            $prod = $own->filter(fn (ClassReference $reference): bool => $reference->scope === 'prod');
            $binaries = $package === null ? [] : $package->binaries;
            $providers = $package === null ? [] : $package->providers;
            $aliases = $package === null ? [] : $package->aliases;

            $packages[] = new PackageUsage(
                name: $name,
                section: $section,
                type: $package?->type,
                namespaces: $package === null ? [] : $package->psr4,
                prodRefs: (int) $prod->sum(fn (ClassReference $reference): int => $reference->count),
                testRefs: (int) $own->filter(fn (ClassReference $reference): bool => $reference->scope === 'test')->sum(fn (ClassReference $reference): int => $reference->count),
                topFiles: $this->topFiles($prod),
                providers: $providers,
                aliases: $aliases,
                providerDiscovered: ($providers !== [] || $aliases !== [])
                    && ! in_array('*', $dontDiscover, true)
                    && ! in_array($name, $dontDiscover, true),
                binaries: $binaries,
                binaryUsed: $binaryUsage->used($binaries),
                hasFilesAutoload: $package !== null && $package->hasFilesAutoload,
            );
        }

        $transitive = $references
            ->filter(fn (ClassReference $reference): bool => $reference->scope === 'prod' && ! array_key_exists($reference->package, $direct))
            ->groupBy(fn (ClassReference $reference): string => $reference->package)
            ->map(fn (Collection $group, int|string $name): TransitiveUsage => new TransitiveUsage(
                (string) $name,
                (int) $group->sum(fn (ClassReference $reference): int => $reference->count),
                $this->topFiles($group),
            ))
            ->sortBy(fn (TransitiveUsage $usage): string => $usage->name)
            ->values()
            ->all();

        return new UsageMap($packages, $transitive, [
            'Global helper functions from "files" autoloads are not detected (see has_files_autoload).',
            'Dynamic class names built from strings are not detected.',
        ]);
    }

    /** @param list<InstalledPackage> $installed */
    private function find(array $installed, string $name): ?InstalledPackage
    {
        foreach ($installed as $package) {
            if ($package->name === $name) {
                return $package;
            }
        }

        return null;
    }

    /** @return list<ClassReference> */
    private function references(): array
    {
        $references = [];

        foreach ($this->sourceFiles() as [$file, $scope]) {
            foreach ($this->countsIn($file) as [$package, $count]) {
                $references[] = new ClassReference($package, $scope, $file, $count);
            }
        }

        return $references;
    }

    /** @return list<array{string, 'prod'|'test'}> relative file and scope */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::PRODUCTION_DIRS as $dir) {
            foreach ($this->project->phpFiles($dir, $dir === 'bootstrap' ? ['bootstrap/cache'] : []) as $file) {
                $files[] = [$file, 'prod'];
            }
        }

        foreach ($this->project->phpFiles(self::TEST_DIR) as $file) {
            $files[] = [$file, 'test'];
        }

        return $files;
    }

    /** @return list<array{string, int}> package and reference count */
    private function countsIn(string $file): array
    {
        $code = (string) file_get_contents($this->project->path($file));
        $blade = str_ends_with($file, '.blade.php');
        $packages = [
            ...array_filter(array_map($this->namespaces->packageFor(...), ClassNames::in($code, $blade))),
            ...($blade ? ClassNames::bladePackages($code) : []),
        ];

        return (new Collection($packages))
            ->countBy()
            ->map(fn (int $count, int|string $package): array => [(string) $package, $count])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<array-key, ClassReference>  $references
     * @return list<string>
     */
    private function topFiles(Collection $references): array
    {
        return $references
            ->groupBy(fn (ClassReference $reference): string => $reference->file)
            ->map(fn (Collection $group): int => (int) $group->sum(fn (ClassReference $reference): int => $reference->count))
            ->sortDesc()
            ->keys()
            ->take(5)
            ->map(fn (int|string $file): string => (string) $file)
            ->values()
            ->all();
    }
}
