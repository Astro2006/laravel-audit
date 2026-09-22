<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Astro2006\LaravelAudit\Enums\PackageManager;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Read-only facts about the audited project, from its files.
 */
class ProjectInspector
{
    private ?ComposerPackages $composer = null;

    /** @var array<mixed>|null */
    private ?array $packageJson = null;

    private ?PhpunitConfig $phpunit = null;

    private string|false|null $herdCoverageIni = null;

    public function __construct(public readonly string $basePath) {}

    /** The app this package is installed in; `base_path` in the config points elsewhere (tests). */
    public static function fromConfig(AuditConfig $config): self
    {
        return new self($config->nullableString('base_path') ?? base_path());
    }

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

    /** Packages from composer.json, composer.lock and vendor/composer/installed.json. */
    public function composer(): ComposerPackages
    {
        return $this->composer ??= new ComposerPackages($this);
    }

    public function isInstalled(string $package): bool
    {
        return $this->composer()->isInstalled($package);
    }

    /** @return array<mixed> */
    public function packageJson(): array
    {
        return $this->packageJson ??= Json::readFile($this->path('package.json'));
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

    public function phpunit(): PhpunitConfig
    {
        return $this->phpunit ??= new PhpunitConfig($this);
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

    /** Where the Pest browser tests live: a test suite or a directory with tests. */
    public function browserTests(string $suite, string $path): ?BrowserTestLocation
    {
        $path = trim($path, '/');

        if (in_array($suite, $this->phpunit()->testSuites(), true)) {
            return new BrowserTestLocation('suite', $suite, $this->phpunit()->suiteDirectory($suite) ?? $path);
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
}
