<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Data\Composer\AbandonedPackage;
use Astro2006\LaravelAudit\Data\Composer\DependencyFactsReport;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\Location;

/**
 * Composer knows the advisories, the abandoned packages and the locked
 * packages; a dependency finding that claims otherwise is dropped.
 */
final readonly class DependencyGuard implements FindingGuard
{
    /** @param list<string> $locked lower-cased names from composer.lock */
    public function __construct(
        private DependencyFactsReport $facts,
        private array $locked,
    ) {}

    public function reject(Finding $finding): ?string
    {
        $packages = array_values(array_filter(array_map(fn (Location $location): ?string => $location->package, $finding->locations)));

        foreach ($packages as $package) {
            if ($this->locked !== [] && ! in_array($package, $this->locked, true) && $package !== 'php' && ! str_starts_with($package, 'ext-')) {
                return sprintf('`package:%s` is not in composer.lock.', $package);
            }
        }

        if ($finding->check === 'vulnerable-dependency') {
            $vulnerable = array_map('strtolower', $this->facts->advisoryPackages());

            if ($packages === [] || array_diff($packages, $vulnerable) !== []) {
                return sprintf('composer audit reports no advisory for %s.', $packages === [] ? 'this finding' : implode(', ', array_map(fn (string $package): string => '`'.$package.'`', array_diff($packages, $vulnerable))));
            }
        }

        if ($finding->check === 'abandoned-package') {
            $abandoned = array_map(fn (AbandonedPackage $package): string => strtolower($package->package), $this->facts->abandoned);

            if ($packages === [] || array_diff($packages, $abandoned) !== []) {
                return sprintf('Composer reports no abandoned package %s.', $packages === [] ? 'for this finding' : implode(', ', array_map(fn (string $package): string => '`'.$package.'`', array_diff($packages, $abandoned))));
            }
        }

        return null;
    }
}
