<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Composer\AbandonedPackage;
use Astro2006\LaravelAudit\Data\Composer\Advisory;
use Astro2006\LaravelAudit\Data\Composer\DependencyFactsReport;
use Astro2006\LaravelAudit\Data\Composer\FlaggedConstraint;
use Astro2006\LaravelAudit\Data\Composer\FlaggedLicense;
use Astro2006\LaravelAudit\Data\Composer\OutdatedPackage;
use Astro2006\LaravelAudit\Data\Composer\PackageCandidate;
use Astro2006\LaravelAudit\Data\Composer\PackageUsage;
use Astro2006\LaravelAudit\Data\Composer\TransitiveUsage;
use Astro2006\LaravelAudit\Data\Composer\UsageMap;
use Astro2006\LaravelAudit\Data\Composer\ValidateResult;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Collection;

/**
 * Everything Composer can tell about the dependencies, computed before the
 * agent starts. Candidates are not findings — the agent confirms them.
 */
final class DependencyFacts
{
    /** Packages whose namespaces apps commonly use through the framework. */
    private const array FRAMEWORK_PROVIDED = ['psr/', 'symfony/', 'nesbot/carbon', 'illuminate/', 'laravel/serializable-closure', 'league/flysystem', 'monolog/monolog', 'guzzlehttp/guzzle', 'guzzlehttp/psr7', 'ramsey/uuid', 'brick/math', 'nunomaduro/termwind', 'vlucas/phpdotenv'];

    /** Dev packages that are used implicitly and never flagged as unused. */
    private const array IMPLICIT = ['laravel/framework', 'laravel/tinker', 'laravel/sail', 'laravel/pail', 'laravel/pint', 'laravel/boost', 'larastan/larastan', 'nunomaduro/collision', 'phpunit/phpunit', 'mockery/mockery', 'fakerphp/faker', 'spatie/laravel-ignition', 'astro2006/laravel-audit', 'barryvdh/laravel-ide-helper', 'barryvdh/laravel-debugbar', 'roave/security-advisories'];

    /**
     * @param  array<mixed>  $audit  composer audit --format=json
     * @param  array<mixed>  $outdated  composer outdated --direct --format=json
     * @param  array<mixed>  $licenses  composer licenses --format=json
     * @param  array<string, list<string>>  $why  package => composer why lines
     * @param  list<string>  $licenseAllowlist
     */
    public function build(
        ProjectInspector $project,
        array $audit,
        array $outdated,
        array $licenses,
        string $validateOutput,
        int $validateExitCode,
        UsageMap $usageMap,
        array $why,
        array $licenseAllowlist,
    ): DependencyFactsReport {
        return new DependencyFactsReport(
            advisories: $this->advisories($project, $audit, $why),
            abandoned: $this->abandoned($audit, $outdated),
            outdatedMajor: $this->outdatedMajor($outdated),
            misplacedCandidates: $this->misplaced($usageMap),
            unusedCandidates: $this->unused($usageMap),
            shadowCandidates: $this->shadow($usageMap),
            licensesFlagged: $this->licenses($project, $licenses, $licenseAllowlist),
            constraintsFlagged: $this->constraints($project),
            composerConfig: $this->composerConfig($project),
            validate: new ValidateResult(
                ok: $validateExitCode === 0,
                lockInSync: ! str_contains(strtolower($validateOutput), 'lock file is not up to date')
                    && is_file($project->path('composer.lock')),
                output: mb_substr(trim($validateOutput), 0, 2000),
            ),
        );
    }

    /**
     * Transitive packages flagged in the audit; they need "composer why".
     *
     * @param  array<mixed>  $audit
     * @return list<string>
     */
    public static function packagesNeedingWhy(array $audit, ProjectInspector $project): array
    {
        $direct = $project->directPackages();
        $packages = array_map('strtolower', [...Read::keys($audit, 'advisories'), ...Read::keys($audit, 'abandoned')]);

        return array_values(array_unique(array_filter(
            $packages,
            fn (string $package): bool => ! array_key_exists($package, $direct),
        )));
    }

    /**
     * @param  array<mixed>  $audit
     * @param  array<string, list<string>>  $why
     * @return list<Advisory>
     */
    private function advisories(ProjectInspector $project, array $audit, array $why): array
    {
        $direct = new Collection($project->directPackages());
        $whyLines = new Collection($why);
        $result = [];

        foreach (Read::array($audit, 'advisories') as $package => $advisories) {
            $package = strtolower((string) $package);

            foreach (array_values(array_filter(is_array($advisories) ? $advisories : [], 'is_array')) as $advisory) {
                $result[] = new Advisory(
                    package: $package,
                    installed: $project->installedVersion($package),
                    advisory: Read::nullableString($advisory, 'advisoryId'),
                    cve: Read::nullableString($advisory, 'cve'),
                    title: Read::nullableString($advisory, 'title'),
                    severity: Read::nullableString($advisory, 'severity'),
                    affected: Read::nullableString($advisory, 'affectedVersions'),
                    link: Read::nullableString($advisory, 'link'),
                    direct: $direct->has($package),
                    section: $direct->get($package),
                    via: $this->via($whyLines->get($package, [])),
                );
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function via(array $lines): array
    {
        $via = [];

        foreach ($lines as $line) {
            $parent = Regex::groups('/^([a-z0-9_.-]+\/[a-z0-9_.-]+)\s/i', trim($line));

            if ($parent !== null) {
                [$package] = $parent;
                $via[] = strtolower($package);
            }
        }

        return array_values(array_unique($via));
    }

    /**
     * @param  array<mixed>  $audit
     * @param  array<mixed>  $outdated
     * @return list<AbandonedPackage>
     */
    private function abandoned(array $audit, array $outdated): array
    {
        /** @var array<string, string|null> $abandoned */
        $abandoned = [];

        foreach (Read::array($audit, 'abandoned') as $package => $replacement) {
            $abandoned[strtolower((string) $package)] = is_string($replacement) && $replacement !== '' ? $replacement : null;
        }

        foreach (Read::arrays($outdated, 'installed') as $package) {
            $flag = data_get($package, 'abandoned', false);
            $name = strtolower(Read::string($package, 'name'));

            if ($flag !== false && $name !== '' && ! array_key_exists($name, $abandoned)) {
                $abandoned[$name] = is_string($flag) ? $flag : null;
            }
        }

        return (new Collection($abandoned))
            ->sortKeys()
            ->map(fn (?string $replacement, int|string $package): AbandonedPackage => new AbandonedPackage((string) $package, $replacement))
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $outdated
     * @return list<OutdatedPackage>
     */
    private function outdatedMajor(array $outdated): array
    {
        $result = [];

        foreach (Read::arrays($outdated, 'installed') as $package) {
            $installed = Read::string($package, 'version');
            $latest = Read::string($package, 'latest');

            if ($installed !== '' && $latest !== '' && $this->isMajorBehind($installed, $latest)) {
                $result[] = new OutdatedPackage(strtolower(Read::string($package, 'name')), $installed, $latest);
            }
        }

        return $result;
    }

    private function isMajorBehind(string $installed, string $latest): bool
    {
        $a = $this->versionParts($installed);
        $b = $this->versionParts($latest);

        if ($a === null || $b === null) {
            return false;
        }

        [$aMajor, $aMinor] = $a;
        [$bMajor, $bMinor] = $b;

        // 0.x releases treat the minor version as the breaking one.
        return $aMajor === 0 && $bMajor === 0 ? $bMinor > $aMinor : $bMajor > $aMajor;
    }

    /** @return array{int, int}|null */
    private function versionParts(string $version): ?array
    {
        $parts = Regex::groups('/^v?(\d+)\.(\d+)/', $version);

        if ($parts === null) {
            return null;
        }

        [$major, $minor] = $parts;

        return [(int) $major, (int) $minor];
    }

    /** @return list<PackageCandidate> */
    private function misplaced(UsageMap $usageMap): array
    {
        $result = [];

        foreach ($usageMap->packages as $package) {
            if (in_array($package->name, self::IMPLICIT, true)) {
                continue;
            }

            if ($package->section === 'require-dev' && $package->prodRefs > 0) {
                $result[] = new PackageCandidate($package->name, 'require-dev', $package->prodRefs, $package->testRefs, $package->topFiles, 'move to require');
            }

            if ($package->section === 'require' && $package->prodRefs === 0 && $package->testRefs > 0 && ! $package->providerDiscovered) {
                $result[] = new PackageCandidate($package->name, 'require', 0, $package->testRefs, [], 'move to require-dev');
            }
        }

        return $result;
    }

    /** @return list<PackageCandidate> */
    private function unused(UsageMap $usageMap): array
    {
        return array_values(array_map(
            fn (PackageUsage $package): PackageCandidate => new PackageCandidate($package->name, $package->section, hasFilesAutoload: $package->hasFilesAutoload),
            array_filter($usageMap->packages, fn (PackageUsage $package): bool => ! in_array($package->name, self::IMPLICIT, true)
                && ! str_starts_with($package->name, 'pestphp/')
                && $package->type !== 'composer-plugin'
                && $package->prodRefs === 0
                && $package->testRefs === 0
                && ! $package->providerDiscovered
                && ! $package->binaryUsed),
        ));
    }

    /** @return list<PackageCandidate> */
    private function shadow(UsageMap $usageMap): array
    {
        $result = [];

        foreach ($usageMap->transitive as $usage) {
            if (! $this->isFrameworkProvided($usage)) {
                $result[] = new PackageCandidate($usage->name, prodRefs: $usage->prodRefs, topFiles: $usage->topFiles);
            }
        }

        return $result;
    }

    private function isFrameworkProvided(TransitiveUsage $usage): bool
    {
        foreach (self::FRAMEWORK_PROVIDED as $prefix) {
            if (str_starts_with($usage->name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $licenses
     * @param  list<string>  $allowlist
     * @return list<FlaggedLicense>
     */
    private function licenses(ProjectInspector $project, array $licenses, array $allowlist): array
    {
        $root = data_get($project->composerJson(), 'license');
        $rootLicense = match (true) {
            is_array($root) => implode(' OR ', array_map('strval', $root)),
            is_string($root) => $root,
            default => 'none (assumed proprietary)',
        };
        $allowed = array_map('strtolower', $allowlist);
        $result = [];

        foreach (Read::array($licenses, 'dependencies') as $package => $data) {
            $packageLicenses = Read::strings(is_array($data) ? $data : [], 'license');
            $ok = array_intersect(array_map('strtolower', $packageLicenses), $allowed) !== [];

            if (! $ok) {
                $result[] = new FlaggedLicense(
                    strtolower((string) $package),
                    $packageLicenses === [] ? 'unknown' : implode(' OR ', $packageLicenses),
                    $rootLicense,
                );
            }
        }

        return $result;
    }

    /** @return list<FlaggedConstraint> */
    private function constraints(ProjectInspector $project): array
    {
        $composer = $project->composerJson();
        $result = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (Read::stringMap($composer, $section) as $package => $constraint) {
                $reason = match (true) {
                    trim($constraint) === '*' => 'any version',
                    str_starts_with($constraint, 'dev-') || str_contains($constraint, '@dev') => 'development branch',
                    str_contains($constraint, '>') && ! str_contains($constraint, '<') && ! str_contains($constraint, '^') && ! str_contains($constraint, '~') => 'no upper bound',
                    default => null,
                };

                if ($reason !== null) {
                    $result[] = new FlaggedConstraint(strtolower($package), $constraint, $reason);
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function composerConfig(ProjectInspector $project): array
    {
        $composer = $project->composerJson();
        $issues = [];

        if (Read::string($composer, 'minimum-stability', 'stable') === 'dev' && data_get($composer, 'prefer-stable') !== true) {
            $issues[] = 'minimum-stability is "dev" without prefer-stable: true';
        }

        if (data_get($composer, 'config.allow-plugins') === true) {
            $issues[] = 'config.allow-plugins is true for every plugin';
        }

        foreach (Read::arrays($composer, 'repositories') as $repository) {
            $type = Read::string($repository, 'type');

            if (in_array($type, ['vcs', 'path', 'artifact', 'package'], true)) {
                $issues[] = sprintf('custom repository of type "%s": %s', $type, Read::string($repository, 'url', (string) json_encode($repository)));
            }
        }

        $platform = Read::nullableString($composer, 'config.platform.php');

        if ($platform !== null) {
            $issues[] = 'config.platform.php is set to '.$platform.' — compare with the production PHP version';
        }

        return $issues;
    }
}
