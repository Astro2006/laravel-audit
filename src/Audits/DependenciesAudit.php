<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Preflight\Data\Composer\AbandonedPackage;
use Astro2006\LaravelAudit\Preflight\Data\Composer\Advisory;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Support\ProjectInspector;

final class DependenciesAudit extends Audit
{
    public const string FACTS_FILE = 'composer/dependency-facts.json';

    public function type(): AuditType
    {
        return AuditType::Dependencies;
    }

    public function idPrefix(): string
    {
        return 'DEP';
    }

    public function title(): string
    {
        return 'Dependencies Audit';
    }

    public function skipReason(AuditTask $task, PreflightSummary $preflight): ?string
    {
        if ($task->name !== 'security') {
            return null;
        }

        $facts = $preflight->dependencyFacts();

        return match (true) {
            $facts === null => 'no dependency facts from the preflight.',
            $facts->advisories === [] && $facts->abandoned === [] => 'composer audit reports no advisories and no abandoned packages.',
            default => null,
        };
    }

    public function findingGuard(PreflightSummary $preflight, ProjectInspector $project): ?FindingGuard
    {
        $facts = $preflight->dependencyFacts();

        return $facts === null ? null : new DependencyGuard($facts, $project->composer()->lockedPackages()->keys()->values()->all());
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        $facts = $preflight->dependencyFacts();

        if ($facts === null) {
            return [];
        }

        $required = [];

        foreach ($facts->advisoryPackages() as $package) {
            $advisories = array_filter($facts->advisories, fn (Advisory $advisory): bool => $advisory->package === $package);
            $ids = array_filter(array_map(fn (Advisory $advisory): ?string => $advisory->advisory, $advisories));

            $required[] = new RequiredFinding(
                check: 'vulnerable-dependency',
                description: sprintf('a `vulnerable-dependency` finding with location `package:%s` (advisory %s).', $package, $ids === [] ? 'see the Composer snapshot' : implode(', ', $ids)),
                target: 'package:'.$package,
            );
        }

        $abandoned = array_values(array_unique(array_map(fn (AbandonedPackage $package): string => $package->package, $facts->abandoned)));

        foreach (array_filter($abandoned) as $package) {
            $required[] = new RequiredFinding(
                check: 'abandoned-package',
                description: sprintf('an `abandoned-package` finding with location `package:%s`.', $package),
                target: 'package:'.$package,
            );
        }

        if (! $facts->validate->lockInSync) {
            $required[] = new RequiredFinding(
                check: 'lock-out-of-sync',
                description: 'a `lock-out-of-sync` finding, because composer validate reports an outdated lock file.',
            );
        }

        return $required;
    }
}
