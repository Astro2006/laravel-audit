<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\ProjectInspector;

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
     * @param  array<string, mixed>  $audit  composer audit --format=json
     * @param  array<string, mixed>  $outdated  composer outdated --direct --format=json
     * @param  array<string, mixed>  $licenses  composer licenses --format=json
     * @param  array<string, mixed>  $usageMap  output of UsageMapBuilder
     * @param  array<string, list<string>>  $why  package => composer why lines
     * @param  list<string>  $licenseAllowlist
     * @return array<string, mixed>
     */
    public function build(
        ProjectInspector $project,
        array $audit,
        array $outdated,
        array $licenses,
        string $validateOutput,
        int $validateExitCode,
        array $usageMap,
        array $why,
        array $licenseAllowlist,
    ): array {
        $direct = $project->directPackages();

        return [
            'advisories' => $this->advisories($project, $audit, $direct, $why),
            'abandoned' => $this->abandoned($audit, $outdated),
            'outdated_major' => $this->outdatedMajor($outdated),
            'misplaced_candidates' => $this->misplaced($usageMap),
            'unused_candidates' => $this->unused($usageMap),
            'shadow_candidates' => $this->shadow($usageMap),
            'licenses_flagged' => $this->licenses($project, $licenses, $licenseAllowlist),
            'constraints_flagged' => $this->constraints($project),
            'composer_config' => $this->composerConfig($project),
            'validate' => [
                'ok' => $validateExitCode === 0,
                'lock_in_sync' => ! str_contains(strtolower($validateOutput), 'lock file is not up to date')
                    && is_file($project->path('composer.lock')),
                'output' => mb_substr(trim($validateOutput), 0, 2000),
            ],
        ];
    }

    /**
     * Packages flagged in the audit that need "composer why".
     *
     * @param  array<string, mixed>  $audit
     * @return list<string>
     */
    public static function packagesNeedingWhy(array $audit, ProjectInspector $project): array
    {
        $packages = array_keys((array) ($audit['advisories'] ?? []));
        $packages = [...$packages, ...array_keys((array) ($audit['abandoned'] ?? []))];
        $direct = $project->directPackages();

        return array_values(array_unique(array_filter(
            array_map('strtolower', array_map('strval', $packages)),
            fn (string $package): bool => ! isset($direct[$package]),
        )));
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, string>  $direct
     * @param  array<string, list<string>>  $why
     * @return list<array<string, mixed>>
     */
    private function advisories(ProjectInspector $project, array $audit, array $direct, array $why): array
    {
        $result = [];

        foreach ((array) ($audit['advisories'] ?? []) as $package => $advisories) {
            $package = strtolower((string) $package);

            foreach ((array) $advisories as $advisory) {
                $result[] = [
                    'package' => $package,
                    'installed' => $project->installedVersion($package),
                    'advisory' => $advisory['advisoryId'] ?? null,
                    'cve' => $advisory['cve'] ?? null,
                    'title' => $advisory['title'] ?? null,
                    'severity' => $advisory['severity'] ?? null,
                    'affected' => $advisory['affectedVersions'] ?? null,
                    'link' => $advisory['link'] ?? null,
                    'direct' => isset($direct[$package]),
                    'section' => $direct[$package] ?? null,
                    'via' => $this->via($why[$package] ?? []),
                ];
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
            if (preg_match('/^([a-z0-9_.-]+\/[a-z0-9_.-]+)\s/i', trim($line), $match) === 1) {
                $via[] = strtolower($match[1]);
            }
        }

        return array_values(array_unique($via));
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $outdated
     * @return list<array{package: string, replacement: string|null}>
     */
    private function abandoned(array $audit, array $outdated): array
    {
        $abandoned = [];

        foreach ((array) ($audit['abandoned'] ?? []) as $package => $replacement) {
            $abandoned[strtolower((string) $package)] = is_string($replacement) && $replacement !== '' ? $replacement : null;
        }

        foreach ((array) ($outdated['installed'] ?? []) as $package) {
            $flag = $package['abandoned'] ?? false;

            if ($flag !== false && isset($package['name'])) {
                $abandoned[strtolower((string) $package['name'])] ??= is_string($flag) ? $flag : null;
            }
        }

        ksort($abandoned);

        return array_map(
            fn (string $package, ?string $replacement): array => ['package' => $package, 'replacement' => $replacement],
            array_keys($abandoned),
            array_values($abandoned),
        );
    }

    /**
     * @param  array<string, mixed>  $outdated
     * @return list<array{package: string, installed: string, latest: string}>
     */
    private function outdatedMajor(array $outdated): array
    {
        $result = [];

        foreach ((array) ($outdated['installed'] ?? []) as $package) {
            $installed = (string) ($package['version'] ?? '');
            $latest = (string) ($package['latest'] ?? '');

            if ($installed === '' || $latest === '' || ! $this->isMajorBehind($installed, $latest)) {
                continue;
            }

            $result[] = ['package' => strtolower((string) $package['name']), 'installed' => $installed, 'latest' => $latest];
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

        // 0.x releases treat the minor version as the breaking one.
        return $a[0] === 0 && $b[0] === 0 ? $b[1] > $a[1] : $b[0] > $a[0];
    }

    /** @return array{int, int}|null */
    private function versionParts(string $version): ?array
    {
        return preg_match('/^v?(\d+)\.(\d+)/', $version, $match) === 1 ? [(int) $match[1], (int) $match[2]] : null;
    }

    /**
     * @param  array<string, mixed>  $usageMap
     * @return list<array<string, mixed>>
     */
    private function misplaced(array $usageMap): array
    {
        $result = [];

        foreach ((array) ($usageMap['packages'] ?? []) as $name => $package) {
            if (in_array($name, self::IMPLICIT, true)) {
                continue;
            }

            $prod = (int) ($package['prod_refs'] ?? 0);
            $test = (int) ($package['test_refs'] ?? 0);

            if ($package['section'] === 'require-dev' && $prod > 0) {
                $result[] = ['package' => $name, 'section' => 'require-dev', 'prod_refs' => $prod, 'top_files' => $package['top_files'] ?? [], 'suggestion' => 'move to require'];
            }

            if ($package['section'] === 'require' && $prod === 0 && $test > 0 && ! ($package['provider_discovered'] ?? false)) {
                $result[] = ['package' => $name, 'section' => 'require', 'prod_refs' => 0, 'test_refs' => $test, 'suggestion' => 'move to require-dev'];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $usageMap
     * @return list<array<string, mixed>>
     */
    private function unused(array $usageMap): array
    {
        $result = [];

        foreach ((array) ($usageMap['packages'] ?? []) as $name => $package) {
            if (in_array($name, self::IMPLICIT, true)
                || str_starts_with($name, 'pestphp/')
                || ($package['type'] ?? null) === 'composer-plugin') {
                continue;
            }

            if ((int) ($package['prod_refs'] ?? 0) === 0
                && (int) ($package['test_refs'] ?? 0) === 0
                && ! ($package['provider_discovered'] ?? false)
                && ! ($package['binary_used'] ?? false)) {
                $result[] = [
                    'package' => $name,
                    'section' => $package['section'],
                    'prod_refs' => 0,
                    'test_refs' => 0,
                    'provider_discovered' => false,
                    'binary_used' => false,
                    'has_files_autoload' => $package['has_files_autoload'] ?? false,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $usageMap
     * @return list<array<string, mixed>>
     */
    private function shadow(array $usageMap): array
    {
        $result = [];

        foreach ((array) ($usageMap['transitive_usage'] ?? []) as $name => $usage) {
            foreach (self::FRAMEWORK_PROVIDED as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }

            $result[] = ['package' => $name, 'prod_refs' => $usage['prod_refs'], 'top_files' => $usage['top_files']];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $licenses
     * @param  list<string>  $allowlist
     * @return list<array<string, mixed>>
     */
    private function licenses(ProjectInspector $project, array $licenses, array $allowlist): array
    {
        $root = $project->composerJson()['license'] ?? null;
        $rootLicense = is_array($root) ? implode(' OR ', $root) : ($root ?? 'none (assumed proprietary)');
        $allowed = array_map('strtolower', $allowlist);
        $result = [];

        foreach ((array) ($licenses['dependencies'] ?? []) as $package => $data) {
            $packageLicenses = array_map('strval', (array) ($data['license'] ?? []));

            $ok = false;

            foreach ($packageLicenses as $license) {
                if (in_array(strtolower($license), $allowed, true)) {
                    $ok = true;
                }
            }

            if (! $ok) {
                $result[] = [
                    'package' => strtolower((string) $package),
                    'license' => $packageLicenses === [] ? 'unknown' : implode(' OR ', $packageLicenses),
                    'root_license' => $rootLicense,
                ];
            }
        }

        return $result;
    }

    /** @return list<array{package: string, constraint: string, reason: string}> */
    private function constraints(ProjectInspector $project): array
    {
        $composer = $project->composerJson();
        $result = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($composer[$section] ?? []) as $package => $constraint) {
                $constraint = (string) $constraint;
                $reason = match (true) {
                    trim($constraint) === '*' => 'any version',
                    str_starts_with($constraint, 'dev-') || str_contains($constraint, '@dev') => 'development branch',
                    str_contains($constraint, '>') && ! str_contains($constraint, '<') && ! str_contains($constraint, '^') && ! str_contains($constraint, '~') => 'no upper bound',
                    default => null,
                };

                if ($reason !== null) {
                    $result[] = ['package' => strtolower((string) $package), 'constraint' => $constraint, 'reason' => $reason];
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

        if (($composer['minimum-stability'] ?? 'stable') === 'dev' && ($composer['prefer-stable'] ?? false) !== true) {
            $issues[] = 'minimum-stability is "dev" without prefer-stable: true';
        }

        if (($composer['config']['allow-plugins'] ?? null) === true) {
            $issues[] = 'config.allow-plugins is true for every plugin';
        }

        foreach ((array) ($composer['repositories'] ?? []) as $repository) {
            if (is_array($repository) && in_array($repository['type'] ?? null, ['vcs', 'path', 'artifact', 'package'], true)) {
                $issues[] = sprintf('custom repository of type "%s": %s', $repository['type'], $repository['url'] ?? json_encode($repository));
            }
        }

        if (isset($composer['config']['platform']['php'])) {
            $issues[] = 'config.platform.php is set to '.$composer['config']['platform']['php'].' — compare with the production PHP version';
        }

        return $issues;
    }
}
