<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Where does the app use each Composer package? Counts class references in
 * production code and tests, so the dependencies agent does not have to
 * grep the codebase itself.
 */
final class UsageMapBuilder
{
    private const array PRODUCTION_DIRS = ['app', 'config', 'database', 'routes', 'bootstrap', 'resources/views'];

    private const string TEST_DIR = 'tests';

    /** Blade markers that reveal a package without a class reference. */
    private const array BLADE_MARKERS = [
        'livewire/livewire' => ['@livewire', '<livewire:', 'wire:model', 'wire:click', '@livewireStyles', '@livewireScripts'],
        'filament/filament' => ['<x-filament'],
        'inertiajs/inertia-laravel' => ['@inertia'],
    ];

    /** @var array<string, string> namespace prefix => package, longest first */
    private array $namespaces = [];

    /** @var array<string, string|null> */
    private array $resolved = [];

    public function __construct(private readonly ProjectInspector $project) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $this->indexNamespaces();

        $direct = $this->project->directPackages();
        $usage = [];

        foreach ($this->sourceFiles() as $file => $scope) {
            foreach ($this->referencesIn($file) as $package => $count) {
                $usage[$package][$scope] = ($usage[$package][$scope] ?? 0) + $count;

                if ($scope === 'prod') {
                    $usage[$package]['files'][$file] = ($usage[$package]['files'][$file] ?? 0) + $count;
                }
            }
        }

        $installed = $this->installedPackages();
        $dontDiscover = array_map('strtolower', (array) ($this->project->composerJson()['extra']['laravel']['dont-discover'] ?? []));
        $scripts = $this->scriptText();

        $packages = [];

        foreach ($direct as $name => $section) {
            $package = $installed[$name] ?? [];
            $providers = (array) ($package['extra']['laravel']['providers'] ?? []);
            $aliases = (array) ($package['extra']['laravel']['aliases'] ?? []);
            $binaries = array_map(fn ($bin): string => basename((string) $bin), (array) ($package['bin'] ?? []));
            $files = $usage[$name]['files'] ?? [];
            arsort($files);

            $packages[$name] = [
                'section' => $section,
                'type' => $package['type'] ?? null,
                'namespaces' => array_keys((array) ($package['autoload']['psr-4'] ?? [])),
                'prod_refs' => $usage[$name]['prod'] ?? 0,
                'test_refs' => $usage[$name]['test'] ?? 0,
                'top_files' => array_slice(array_keys($files), 0, 5),
                'providers' => array_values($providers),
                'aliases' => array_keys($aliases),
                'provider_discovered' => ($providers !== [] || $aliases !== [])
                    && ! in_array('*', $dontDiscover, true)
                    && ! in_array($name, $dontDiscover, true),
                'binaries' => $binaries,
                'binary_used' => $this->binaryUsed($binaries, $scripts),
                'has_files_autoload' => ((array) ($package['autoload']['files'] ?? [])) !== [],
            ];
        }

        $transitive = [];

        foreach ($usage as $name => $counts) {
            if (! isset($direct[$name]) && ($counts['prod'] ?? 0) > 0) {
                $files = $counts['files'] ?? [];
                arsort($files);
                $transitive[$name] = ['prod_refs' => $counts['prod'], 'top_files' => array_slice(array_keys($files), 0, 5)];
            }
        }

        ksort($transitive);

        return [
            'packages' => $packages,
            'transitive_usage' => $transitive,
            'limitations' => [
                'Global helper functions from "files" autoloads are not detected (see has_files_autoload).',
                'Dynamic class names built from strings are not detected.',
            ],
        ];
    }

    private function indexNamespaces(): void
    {
        foreach ($this->installedPackages() as $name => $package) {
            foreach (['psr-4', 'psr-0'] as $standard) {
                foreach (array_keys((array) ($package['autoload'][$standard] ?? [])) as $namespace) {
                    $namespace = trim((string) $namespace, '\\');

                    if ($namespace !== '') {
                        $this->namespaces[strtolower($namespace).'\\'] = $name;
                    }
                }
            }
        }

        uksort($this->namespaces, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /** @return array<string, array<string, mixed>> */
    private function installedPackages(): array
    {
        $installed = $this->project->installedJson();
        $packages = $installed['packages'] ?? $installed;
        $result = [];

        foreach ((array) $packages as $package) {
            if (is_array($package) && isset($package['name'])) {
                $result[strtolower((string) $package['name'])] = $package;
            }
        }

        return $result;
    }

    /** @return array<string, 'prod'|'test'> relative file => scope */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::PRODUCTION_DIRS as $dir) {
            foreach ($this->filesIn($dir, $dir === 'bootstrap' ? ['bootstrap/cache'] : []) as $file) {
                $files[$file] = 'prod';
            }
        }

        foreach ($this->filesIn(self::TEST_DIR) as $file) {
            $files[$file] = 'test';
        }

        return $files;
    }

    /**
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function filesIn(string $dir, array $exclude = []): array
    {
        return $this->project->phpFiles($dir, $exclude);
    }

    /** @return array<string, int> package => reference count */
    private function referencesIn(string $file): array
    {
        $code = (string) file_get_contents($this->project->path($file));
        $names = str_ends_with($file, '.blade.php') ? $this->bladeNames($code) : $this->phpNames($code);
        $counts = [];

        foreach ($names as $name) {
            $package = $this->packageFor($name);

            if ($package !== null) {
                $counts[$package] = ($counts[$package] ?? 0) + 1;
            }
        }

        if (str_ends_with($file, '.blade.php')) {
            foreach (self::BLADE_MARKERS as $package => $markers) {
                foreach ($markers as $marker) {
                    if (str_contains($code, $marker)) {
                        $counts[$package] = ($counts[$package] ?? 0) + 1;

                        break;
                    }
                }
            }
        }

        return $counts;
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

            if ($token[0] === T_NAMESPACE) {
                $skipNext = true;

                continue;
            }

            if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
                if ($skipNext) {
                    $skipNext = false;

                    continue;
                }

                $names[] = ltrim($token[1], '\\');
            } elseif ($token[0] !== T_WHITESPACE) {
                $skipNext = false;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function bladeNames(string $code): array
    {
        preg_match_all('/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)/', $code, $matches);

        return $matches[1];
    }

    private function packageFor(string $name): ?string
    {
        $key = strtolower($name).'\\';

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        foreach ($this->namespaces as $prefix => $package) {
            if (str_starts_with($key, $prefix)) {
                return $this->resolved[$key] = $package;
            }
        }

        return $this->resolved[$key] = null;
    }

    private function scriptText(): string
    {
        $text = json_encode($this->project->composerJson()['scripts'] ?? []).json_encode($this->project->packageJson()['scripts'] ?? []);

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
