<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

final class DependenciesAudit extends BaseAudit
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

    public function preflightSteps(): array
    {
        return [
            PreflightStep::About,
            PreflightStep::Stack,
            PreflightStep::LinesOfCode,
            PreflightStep::Composer,
        ];
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        $facts = $preflight->json(self::FACTS_FILE);
        $required = [];
        $seen = [];

        foreach ((array) ($facts['advisories'] ?? []) as $advisory) {
            $package = strtolower((string) ($advisory['package'] ?? ''));

            if ($package === '' || isset($seen['vuln:'.$package])) {
                continue;
            }

            $seen['vuln:'.$package] = true;
            $required[] = new RequiredFinding(
                check: 'vulnerable-dependency',
                description: sprintf('a `vulnerable-dependency` finding with location `package:%s` (advisory %s).', $package, $advisory['advisory'] ?? 'see dependency-facts.json'),
                target: 'package:'.$package,
            );
        }

        foreach ((array) ($facts['abandoned'] ?? []) as $abandoned) {
            $package = strtolower((string) ($abandoned['package'] ?? ''));

            if ($package === '' || isset($seen['abandoned:'.$package])) {
                continue;
            }

            $seen['abandoned:'.$package] = true;
            $required[] = new RequiredFinding(
                check: 'abandoned-package',
                description: sprintf('an `abandoned-package` finding with location `package:%s`.', $package),
                target: 'package:'.$package,
            );
        }

        if (($facts['validate']['lock_in_sync'] ?? true) === false) {
            $required[] = new RequiredFinding(
                check: 'lock-out-of-sync',
                description: 'a `lock-out-of-sync` finding, because composer validate reports an outdated lock file.',
            );
        }

        return $required;
    }
}
