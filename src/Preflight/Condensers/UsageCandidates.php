<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Composer\PackageCandidate;
use Astro2006\LaravelAudit\Preflight\Data\Composer\PackageUsage;
use Astro2006\LaravelAudit\Preflight\Data\Composer\TransitiveUsage;
use Astro2006\LaravelAudit\Preflight\Data\Composer\UsageMap;

final class UsageCandidates
{
    private const array FRAMEWORK_PROVIDED = ['psr/', 'symfony/', 'nesbot/carbon', 'illuminate/', 'laravel/serializable-closure', 'league/flysystem', 'monolog/monolog', 'guzzlehttp/guzzle', 'guzzlehttp/psr7', 'ramsey/uuid', 'brick/math', 'nunomaduro/termwind', 'vlucas/phpdotenv'];

    private const array IMPLICIT = ['laravel/framework', 'laravel/tinker', 'laravel/sail', 'laravel/pail', 'laravel/pint', 'laravel/boost', 'larastan/larastan', 'nunomaduro/collision', 'phpunit/phpunit', 'mockery/mockery', 'fakerphp/faker', 'spatie/laravel-ignition', 'astro2006/laravel-audit', 'barryvdh/laravel-ide-helper', 'barryvdh/laravel-debugbar', 'roave/security-advisories'];

    /** @return list<PackageCandidate> */
    public function misplaced(UsageMap $usageMap): array
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
    public function unused(UsageMap $usageMap): array
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
    public function shadow(UsageMap $usageMap): array
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
}
