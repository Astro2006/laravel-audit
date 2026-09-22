<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Astro2006\LaravelAudit\Data\Composer\InstalledPackage;
use Illuminate\Support\Collection;

/**
 * The project's Composer packages: what composer.json requires, what
 * composer.lock locks and what vendor/ has installed. Files are read once.
 */
final class ComposerPackages
{
    /** @var array<mixed>|null */
    private ?array $composerJson = null;

    /** @var array<mixed>|null */
    private ?array $composerLock = null;

    /** @var list<InstalledPackage>|null */
    private ?array $installed = null;

    /** @var Collection<string, string>|null package => version */
    private ?Collection $locked = null;

    public function __construct(private readonly ProjectInspector $project) {}

    /** @return array<mixed> */
    public function composerJson(): array
    {
        return $this->composerJson ??= Json::readFile($this->project->path('composer.json'));
    }

    /** @return array<mixed> */
    public function composerLock(): array
    {
        return $this->composerLock ??= Json::readFile($this->project->path('composer.lock'));
    }

    /** @return list<InstalledPackage> */
    public function installedPackages(): array
    {
        return $this->installed ??= InstalledPackage::listFromJson(Json::readFile($this->project->path('vendor/composer/installed.json')));
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
}
