<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Composer\OutdatedPackage;
use Astro2006\LaravelAudit\Support\Read;
use Astro2006\LaravelAudit\Support\Regex;

final class OutdatedMajors
{
    /**
     * @param  array<mixed>  $outdated
     * @return list<OutdatedPackage>
     */
    public function build(array $outdated): array
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
}
