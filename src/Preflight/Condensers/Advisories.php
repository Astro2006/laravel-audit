<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Composer\Advisory;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Collection;

final class Advisories
{
    /**
     * @param  array<mixed>  $audit
     * @param  array<string, list<string>>  $why
     * @return list<Advisory>
     */
    public function build(ProjectInspector $project, array $audit, array $why): array
    {
        $direct = new Collection($project->composer()->directPackages());
        $whyLines = new Collection($why);
        $result = [];

        foreach (Read::array($audit, 'advisories') as $package => $advisories) {
            $package = strtolower((string) $package);

            foreach (array_values(array_filter(is_array($advisories) ? $advisories : [], 'is_array')) as $advisory) {
                $result[] = new Advisory(
                    package: $package,
                    installed: $project->composer()->installedVersion($package),
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
}
