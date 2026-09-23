<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Composer\AbandonedPackage;
use Astro2006\LaravelAudit\Preflight\Data\Composer\DependencyFactsReport;
use Astro2006\LaravelAudit\Preflight\Data\Composer\UsageMap;
use Astro2006\LaravelAudit\Preflight\Data\Composer\ValidateResult;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Collection;

final class DependencyFacts
{
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
        $candidates = new UsageCandidates;

        return new DependencyFactsReport(
            advisories: (new Advisories)->build($project, $audit, $why),
            abandoned: $this->abandoned($audit, $outdated),
            outdatedMajor: (new OutdatedMajors)->build($outdated),
            misplacedCandidates: $candidates->misplaced($usageMap),
            unusedCandidates: $candidates->unused($usageMap),
            shadowCandidates: $candidates->shadow($usageMap),
            licensesFlagged: (new LicenseCheck)->flagged($project, $licenses, $licenseAllowlist),
            constraintsFlagged: (new ComposerHygiene)->constraints($project),
            composerConfig: (new ComposerHygiene)->config($project),
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
        $direct = $project->composer()->directPackages();
        $packages = array_map('strtolower', [...Read::keys($audit, 'advisories'), ...Read::keys($audit, 'abandoned')]);

        return array_values(array_unique(array_filter(
            $packages,
            fn (string $package): bool => ! array_key_exists($package, $direct),
        )));
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
}
