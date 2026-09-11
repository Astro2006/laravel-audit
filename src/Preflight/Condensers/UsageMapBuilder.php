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
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Collection;

/**
 * Where does the app use each Composer package? Counts class references in
 * production code and tests, so the dependencies agent does not have to
 * grep the codebase itself.
 */
final class UsageMapBuilder
{
    private const array PRODUCTION_DIRS = ['app', 'config', 'database', 'routes', 'bootstrap', 'resources/views'];

    private const string TEST_DIR = 'tests';

    /** Blade markers that reveal a package without a class reference: package => markers. */
    private const array BLADE_MARKERS = [
        'livewire/livewire' => ['@livewire', '<livewire:', 'wire:model', 'wire:click', '@livewireStyles', '@livewireScripts'],
        'filament/filament' => ['<x-filament'],
        'inertiajs/inertia-laravel' => ['@inertia'],
    ];

    /** @var Collection<string, string> namespace prefix (lower case, trailing backslash) => package, longest first */
    private Collection $namespaces;

    /** @var Collection<string, string|null> */
    private Collection $resolved;

    public function __construct(private readonly ProjectInspector $project)
    {
        $this->namespaces = new Collection;
        $this->resolved = new Collection;
    }

    public function build(): UsageMap
    {
        $installed = $this->project->installedPackages();
        $this->indexNamespaces($installed);

        $references = new Collection($this->references());
        $direct = $this->project->directPackages();
        $dontDiscover = array_map('strtolower', Read::strings($this->project->composerJson(), 'extra.laravel.dont-discover'));
        $scripts = $this->scriptText();

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
                binaryUsed: $this->binaryUsed($binaries, $scripts),
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
    private function indexNamespaces(array $installed): void
    {
        $namespaces = new Collection;

        foreach ($installed as $package) {
            foreach ($package->namespaces as $namespace) {
                $namespaces->put(strtolower($namespace).'\\', $package->name);
            }
        }

        $this->namespaces = $namespaces->sortKeysUsing(fn (string $a, string $b): int => strlen($b) <=> strlen($a));
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
        $packages = array_values(array_filter(array_map(
            fn (string $name): ?string => $this->packageFor($name),
            $blade ? $this->bladeNames($code) : $this->phpNames($code),
        )));

        if ($blade) {
            foreach (self::BLADE_MARKERS as $package => $markers) {
                foreach ($markers as $marker) {
                    if (str_contains($code, $marker)) {
                        $packages[] = $package;

                        break;
                    }
                }
            }
        }

        return (new Collection($packages))
            ->countBy()
            ->map(fn (int $count, int|string $package): array => [(string) $package, $count])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function phpNames(string $code): array
    {
        $names = [];
        $skipNext = false;

        foreach (@token_get_all($code) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_NAMESPACE) {
                $skipNext = true;

                continue;
            }

            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                if (! $skipNext) {
                    $names[] = ltrim($text, '\\');
                }

                $skipNext = false;
            } elseif ($id !== T_WHITESPACE) {
                $skipNext = false;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function bladeNames(string $code): array
    {
        return Regex::all('/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)/', $code);
    }

    private function packageFor(string $name): ?string
    {
        $key = strtolower($name).'\\';

        if (! $this->resolved->has($key)) {
            $this->resolved->put($key, $this->namespaces->first(
                fn (string $package, string $prefix): bool => str_starts_with($key, $prefix),
            ));
        }

        return $this->resolved->get($key);
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

    private function scriptText(): string
    {
        $text = json_encode(Read::array($this->project->composerJson(), 'scripts')).json_encode(Read::array($this->project->packageJson(), 'scripts'));

        foreach (glob($this->project->path('.github/workflows').'/*.{yml,yaml}', GLOB_BRACE) ?: [] as $workflow) {
            $text .= (string) file_get_contents($workflow);
        }

        foreach (['Makefile', 'Taskfile.yml'] as $file) {
            if (is_file($this->project->path($file))) {
                $text .= (string) file_get_contents($this->project->path($file));
            }
        }

        return $text;
    }

    /** @param list<string> $binaries */
    private function binaryUsed(array $binaries, string $scripts): bool
    {
        foreach ($binaries as $binary) {
            if ($binary !== '' && preg_match('/(^|[\s\/"\'])'.preg_quote($binary, '/').'([\s"\']|$)/', $scripts) === 1) {
                return true;
            }
        }

        return false;
    }
}
