<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Audits\DependencyGuard;
use Astro2006\LaravelAudit\Data\Composer\AbandonedPackage;
use Astro2006\LaravelAudit\Data\Composer\Advisory;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Astro2006\LaravelAudit\Support\ProjectInspector;

final class DependenciesAgent extends Agent
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

    public function checks(): array
    {
        return [
            'vulnerable-dependency' => 'A package with a security advisory (one finding per package).',
            'abandoned-package' => 'An abandoned package.',
            'unused-package' => 'A direct dependency without explicit or implicit usage.',
            'misplaced-package' => 'require vs require-dev in the wrong place.',
            'shadow-dependency' => 'Production code using a package that is only installed transitively.',
            'redundant-package' => 'Several packages for the same job, or packages superseded by the framework.',
            'outdated-major' => 'A direct dependency one or more majors behind.',
            'unsupported-runtime' => 'Laravel or PHP version without bug or security fixes.',
            'stale-package' => 'A package without releases for a long time.',
            'loose-constraint' => 'Constraints like *, dev-*, >= without upper bound, or pins that block patches.',
            'lock-out-of-sync' => 'composer.lock missing or out of sync with composer.json.',
            'composer-config' => 'allow-plugins true, untrusted repositories, scripts executing remote code, platform mismatch.',
            'autoload-bloat' => 'Large files autoloads or classmap bloat.',
            'license-risk' => 'Copyleft licenses in a proprietary project, or unknown licenses.',
            ...$this->commonChecks(),
        ];
    }

    public function sections(): array
    {
        return ['Dependency inventory', 'Upgrade plan'];
    }

    protected function taskList(): array
    {
        return [
            $this->task('security', 'Advisories and abandoned packages', ['vulnerable-dependency', 'abandoned-package'], snapshots: [SnapshotType::Composer]),
            $this->task('necessity', 'Necessity and placement', ['unused-package', 'misplaced-package', 'shadow-dependency', 'redundant-package'], sections: ['Dependency inventory'], snapshots: [SnapshotType::Composer]),
            $this->task('freshness', 'Freshness and support', ['outdated-major', 'unsupported-runtime', 'stale-package'], sections: ['Upgrade plan'], snapshots: [SnapshotType::Composer]),
            $this->task('hygiene', 'Constraints, Composer config and licenses', ['loose-constraint', 'lock-out-of-sync', 'composer-config', 'autoload-bloat', 'license-risk'], snapshots: [SnapshotType::Composer]),
        ];
    }

    public function preflightSteps(): array
    {
        return [
            PreflightStep::About,
            PreflightStep::Stack,
            PreflightStep::LinesOfCode,
            PreflightStep::Composer,
        ];
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

        return $facts === null ? null : new DependencyGuard($facts, $project->lockedPackages()->keys()->values()->all());
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
