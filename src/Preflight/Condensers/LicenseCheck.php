<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Composer\FlaggedLicense;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;

final class LicenseCheck
{
    /**
     * @param  array<mixed>  $licenses
     * @param  list<string>  $allowlist
     * @return list<FlaggedLicense>
     */
    public function flagged(ProjectInspector $project, array $licenses, array $allowlist): array
    {
        $root = data_get($project->composer()->composerJson(), 'license');
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
}
