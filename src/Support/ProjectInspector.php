<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Astro2006\LaravelAudit\Data\Composer\InstalledPackage;
use Astro2006\LaravelAudit\Enums\PackageManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use SimpleXMLElement;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Read-only facts about the audited project, from its files.
 */
class ProjectInspector
{
    /** @var array<mixed>|null */
    private ?array $composerJson = null;

    /** @var array<mixed>|null */
    private ?array $composerLock = null;

    /** @var array<mixed>|null */
    private ?array $packageJson = null;

    /** @var list<InstalledPackage>|null */
    private ?array $installed = null;

    /** @var Collection<string, string>|null package => version */
    private ?Collection $locked = null;

    private SimpleXMLElement|false|null $phpunitXml = null;

    private string|false|null $herdCoverageIni = null;

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

    /** @return array<mixed> */
    public function composerJson(): array
    {
        return $this->composerJson ??= Json::readFile($this->path('composer.json'));
    }

    /** @return array<mixed> */
    public function composerLock(): array
    {
        return $this->composerLock ??= Json::readFile($this->path('composer.lock'));
    }

    /** @return array<mixed> */
    public function packageJson(): array
    {
        return $this->packageJson ??= Json::readFile($this->path('package.json'));
    }

    /** @return list<InstalledPackage> */
    public function installedPackages(): array
    {
        return $this->installed ??= InstalledPackage::listFromJson(Json::readFile($this->path('vendor/composer/installed.json')));
    }

    /**
     * Locked packages with their version, from require and require-dev.
     *
     * @return Collection<string, string>
     */
    public function lockedPackages(): Collection
    {
        if ($this->locked !== null) {
            return $this->locked;
        }

        $locked = new Collection;

        foreach ([...Read::arrays($this->composerLock(), 'packages'), ...Read::arrays($this->composerLock(), 'packages-dev')] as $package) {
            $name = strtolower(Read::string($package, 'name'));

            if ($name !== '') {
                $locked->put($name, Read::string($package, 'version'));
            }
        }

        return $this->locked = $locked;
    }

    public function isInstalled(string $package): bool
    {
        return $this->lockedPackages()->has(strtolower($package));
    }

    public function installedVersion(string $package): ?string
    {
        return $this->lockedPackages()->get(strtolower($package));
    }

    public function majorVersion(string $package): ?int
    {
        $version = $this->installedVersion($package);

        $parts = $version === null ? null : Regex::groups('/^v?(\d+)/', $version);

        if ($parts === null) {
            return null;
        }

        [$major] = $parts;

        return (int) $major;
    }

    /**
     * Direct requirements from composer.json, without platform packages.
     *
     * @return array<string, 'require'|'require-dev'>
     */
    public function directPackages(): array
    {
        $direct = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (Read::keys($this->composerJson(), $section) as $name) {
                $name = strtolower($name);

                if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || $name === 'composer-plugin-api' || $name === 'composer-runtime-api') {
                    continue;
                }

                $direct = [...$direct, $name => $section];
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

        return array_values(array_filter(array_map(
            fn (SimpleXMLElement $suite): string => Xml::attr($suite, 'name'),
            Xml::xpath($xml, '//testsuite'),
        )));
    }

    /**
     * <env> and <server> values from the PHPUnit config.
     *
     * @return array<string, string>
     */
    public function phpunitEnv(bool $forcedOnly = false): array
    {
        $xml = $this->phpunitXml();

        if ($xml === null) {
            return [];
        }

        return (new Collection(Xml::xpath($xml, '//php/env | //php/server')))
            ->filter(fn (SimpleXMLElement $node): bool => Xml::attr($node, 'name') !== '')
            ->filter(fn (SimpleXMLElement $node): bool => ! $forcedOnly || in_array(strtolower(Xml::attr($node, 'force')), ['true', '1'], true))
            ->mapWithKeys(fn (SimpleXMLElement $node): array => [Xml::attr($node, 'name') => Xml::attr($node, 'value')])
            ->all();
    }

    public function coverageDriver(): ?string
    {
        return match (true) {
            extension_loaded('pcov') => 'pcov',
            $this->herdCoverageIni() !== null => 'herd',
            extension_loaded('xdebug') => 'xdebug',
            default => null,
        };
    }

    /**
     * The ini Laravel Herd loads for `herd coverage` (Xdebug), when it loads
     * Xdebug for this PHP version. The test step passes it as PHPRC: unlike
     * `herd coverage`'s -c, it reaches the processes the test runner starts.
     */
    public function herdCoverageIni(): ?string
    {
        if ($this->herdCoverageIni === null) {
            $home = (string) (getenv('HOME') ?: '');
            $ini = $home.'/Library/Application Support/Herd/config/php/'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION.'/debug/debug.ini';

            $this->herdCoverageIni = $home !== '' && is_file($ini) && trim(Process::env(['PHPRC' => $ini, 'XDEBUG_MODE' => 'coverage'])
                ->timeout(15)
                ->run([PHP_BINARY, '-r', 'echo extension_loaded("xdebug") ? "on" : "off";'])
                ->output()) === 'on' ? $ini : false;
        }

        return $this->herdCoverageIni ?: null;
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
     * The Laravel Boost MCP server, if Boost is installed: the entry from
     * .mcp.json when there is one (it may use Sail or an absolute PHP), else
     * the default `php artisan boost:mcp`. Independent of the agent CLI.
     */
    public function boostMcpServer(): ?McpServer
    {
        if (! $this->isInstalled('laravel/boost')) {
            return null;
        }

        foreach (Read::array(Json::readFile($this->path('.mcp.json')), 'mcpServers') as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            if ($name === McpServer::NAME || str_contains(implode(' ', Read::strings($config, 'args')), 'boost:mcp')) {
                return new McpServer((string) $name, $config);
            }
        }

        return McpServer::default();
    }

    /** Where the Pest browser tests live: a test suite or a directory with tests. */
    public function browserTests(string $suite, string $path): ?BrowserTestLocation
    {
        $path = trim($path, '/');

        if (in_array($suite, $this->testSuites(), true)) {
            return new BrowserTestLocation('suite', $suite, $this->suiteDirectory($suite) ?? $path);
        }

        if (is_dir($this->path($path)) && $this->phpFiles($path) !== []) {
            return new BrowserTestLocation('path', $path, $path);
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

        foreach ($xml === null ? [] : Xml::xpath($xml, '//testsuite') as $node) {
            $directory = Xml::child($node, 'directory');

            if (Xml::attr($node, 'name') === $suite && $directory !== null) {
                return trim((string) $directory, './');
            }
        }

        return null;
    }

    private function phpunitXml(): ?SimpleXMLElement
    {
        if ($this->phpunitXml === null) {
            $path = $this->phpunitConfigPath();
            $this->phpunitXml = ($path === null ? null : Xml::load($path)) ?? false;
        }

        return $this->phpunitXml ?: null;
    }
}
