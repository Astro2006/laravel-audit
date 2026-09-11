<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Astro2006\LaravelAudit\Enums\PackageManager;
use SimpleXMLElement;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Read-only facts about the audited project, from its files.
 */
class ProjectInspector
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(public readonly string $basePath) {}

    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    public function relative(string $absolute): string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';
        $absolute = str_replace('\\', '/', $absolute);

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }

    /** @return array<string, mixed> */
    public function composerJson(): array
    {
        return $this->cache['composer.json'] ??= Json::readFile($this->path('composer.json'));
    }

    /** @return array<string, mixed> */
    public function composerLock(): array
    {
        return $this->cache['composer.lock'] ??= Json::readFile($this->path('composer.lock'));
    }

    /** @return array<string, mixed> */
    public function packageJson(): array
    {
        return $this->cache['package.json'] ??= Json::readFile($this->path('package.json'));
    }

    /** @return array<string, mixed> */
    public function installedJson(): array
    {
        return $this->cache['installed.json'] ??= Json::readFile($this->path('vendor/composer/installed.json'));
    }

    /**
     * Locked packages with their version, from require and require-dev.
     *
     * @return array<string, string>
     */
    public function lockedPackages(): array
    {
        if (isset($this->cache['locked'])) {
            return $this->cache['locked'];
        }

        $lock = $this->composerLock();
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) ($lock[$section] ?? []) as $package) {
                if (isset($package['name'])) {
                    $packages[strtolower((string) $package['name'])] = (string) ($package['version'] ?? '');
                }
            }
        }

        return $this->cache['locked'] = $packages;
    }

    public function isInstalled(string $package): bool
    {
        return array_key_exists(strtolower($package), $this->lockedPackages());
    }

    public function installedVersion(string $package): ?string
    {
        return $this->lockedPackages()[strtolower($package)] ?? null;
    }

    public function majorVersion(string $package): ?int
    {
        $version = $this->installedVersion($package);

        if ($version === null || preg_match('/^v?(\d+)/', $version, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Direct requirements from composer.json, without platform packages.
     *
     * @return array<string, 'require'|'require-dev'>
     */
    public function directPackages(): array
    {
        $composer = $this->composerJson();
        $direct = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys((array) ($composer[$section] ?? [])) as $name) {
                $name = strtolower((string) $name);

                if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || $name === 'composer-plugin-api' || $name === 'composer-runtime-api') {
                    continue;
                }

                $direct[$name] = $section;
            }
        }

        return $direct;
    }

    public function usesPest(): bool
    {
        return $this->isInstalled('pestphp/pest');
    }

    public function hasParatest(): bool
    {
        return $this->isInstalled('brianium/paratest');
    }

    public function canRunParallel(): bool
    {
        return $this->usesPest() || $this->hasParatest();
    }

    public function phpunitConfigPath(): ?string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $file) {
            if (is_file($this->path($file))) {
                return $this->path($file);
            }
        }

        return null;
    }

    /**
     * Test suite names from the PHPUnit config.
     *
     * @return list<string>
     */
    public function testSuites(): array
    {
        $xml = $this->phpunitXml();

        if ($xml === null) {
            return [];
        }

        $names = [];

        foreach ($xml->xpath('//testsuite') ?: [] as $suite) {
            $name = (string) ($suite['name'] ?? '');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * <env> and <server> values from the PHPUnit config.
     *
     * @return array<string, string>
     */
    public function phpunitEnv(): array
    {
        $xml = $this->phpunitXml();
        $env = [];

        if ($xml === null) {
            return [];
        }

        foreach ($xml->xpath('//php/env | //php/server') ?: [] as $node) {
            $name = (string) ($node['name'] ?? '');

            if ($name !== '') {
                $env[$name] = (string) ($node['value'] ?? '');
            }
        }

        return $env;
    }

    public function coverageDriver(): ?string
    {
        return match (true) {
            extension_loaded('pcov') => 'pcov',
            extension_loaded('xdebug') => 'xdebug',
            default => null,
        };
    }

    public function phpstanConfigPath(): ?string
    {
        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $file) {
            if (is_file($this->path($file))) {
                return $this->path($file);
            }
        }

        return null;
    }

    public function hasVendorBinary(string $name): bool
    {
        return is_file($this->path('vendor/bin/'.$name));
    }

    public function findExecutable(string $name): ?string
    {
        return (new ExecutableFinder)->find($name);
    }

    public function packageManager(): PackageManager
    {
        return PackageManager::detect($this->basePath);
    }

    public function hasNodeModule(string $name): bool
    {
        return is_dir($this->path('node_modules/'.$name));
    }

    /**
     * The Laravel Boost MCP server entry from .mcp.json, if Boost is installed.
     *
     * @return array{name: string, config: array<string, mixed>}|null
     */
    public function boostMcpServer(): ?array
    {
        if (! $this->isInstalled('laravel/boost')) {
            return null;
        }

        $servers = (array) (Json::readFile($this->path('.mcp.json'))['mcpServers'] ?? []);

        foreach ($servers as $name => $config) {
            $args = implode(' ', array_map('strval', (array) ($config['args'] ?? [])));

            if ($name === 'laravel-boost' || str_contains($args, 'boost:mcp')) {
                return ['name' => (string) $name, 'config' => (array) $config];
            }
        }

        return null;
    }

    /**
     * Where the Pest browser tests live: a test suite or a directory with tests.
     *
     * @return array{type: 'suite'|'path', value: string, path: string}|null
     */
    public function browserTests(string $suite, string $path): ?array
    {
        $path = trim($path, '/');

        if (in_array($suite, $this->testSuites(), true)) {
            return ['type' => 'suite', 'value' => $suite, 'path' => $this->suiteDirectory($suite) ?? $path];
        }

        if (is_dir($this->path($path)) && $this->phpFiles($path) !== []) {
            return ['type' => 'path', 'value' => $path, 'path' => $path];
        }

        return null;
    }

    public function hasBrowserPlugin(): bool
    {
        return $this->isInstalled('pestphp/pest-plugin-browser');
    }

    /**
     * PHP files below a project directory, relative to the project root.
     *
     * @param  list<string>  $excludeDirs  relative directories to skip
     * @return list<string>
     */
    public function phpFiles(string $directory, array $excludeDirs = []): array
    {
        $root = $this->path($directory);

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = $this->relative($file->getPathname());

            foreach ($excludeDirs as $exclude) {
                if (str_starts_with($relative, rtrim($exclude, '/').'/')) {
                    continue 2;
                }
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    private function suiteDirectory(string $suite): ?string
    {
        $xml = $this->phpunitXml();

        foreach ($xml?->xpath('//testsuite') ?: [] as $node) {
            if ((string) ($node['name'] ?? '') === $suite && isset($node->directory)) {
                return trim((string) $node->directory[0], './');
            }
        }

        return null;
    }

    private function phpunitXml(): ?SimpleXMLElement
    {
        if (array_key_exists('phpunit.xml', $this->cache)) {
            return $this->cache['phpunit.xml'];
        }

        $path = $this->phpunitConfigPath();
        $xml = null;

        if ($path !== null) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($path) ?: null;
            libxml_use_internal_errors($previous);
        }

        return $this->cache['phpunit.xml'] = $xml;
    }
}
