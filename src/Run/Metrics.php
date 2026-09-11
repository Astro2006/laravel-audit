<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;

/**
 * Deterministic numbers straight from the preflight.
 */
final class Metrics
{
    /** @return list<array{string, string}> label => value */
    public function rows(PreflightSummary $preflight, ?float $riskPoints = null): array
    {
        $rows = [];
        $loc = $preflight->get('loc');

        $rows[] = ['LOC (app/)', $loc === null ? '–' : (string) $loc];

        $tests = $preflight->step(PreflightStep::Tests);
        if ($preflight->ran(PreflightStep::Tests)) {
            $rows[] = ['Tests (passed / failed / skipped)', sprintf('%d / %d / %d', $tests['passed'] ?? 0, ($tests['failed'] ?? 0) + ($tests['errors'] ?? 0), $tests['skipped'] ?? 0)];
            $rows[] = ['Line coverage', isset($tests['coverage_pct']) ? $tests['coverage_pct'].' %' : '–'];
        }

        $browser = $preflight->step(PreflightStep::Browser);
        if ($preflight->ran(PreflightStep::Browser)) {
            $rows[] = ['Browser tests (passed / failed)', sprintf('%d / %d', $browser['passed'] ?? 0, ($browser['failed'] ?? 0) + ($browser['errors'] ?? 0))];
        }

        $phpstan = $preflight->step(PreflightStep::Phpstan);
        if ($preflight->ran(PreflightStep::Phpstan)) {
            $rows[] = ['PHPStan errors (active / baselined) · level', sprintf('%d / %d · %s', $phpstan['errors'] ?? 0, $phpstan['baselined'] ?? 0, $phpstan['level'] ?? '?')];
        }

        if ($preflight->ran(PreflightStep::Pint)) {
            $rows[] = ['Pint files with issues', (string) ($preflight->step(PreflightStep::Pint)['files_with_issues'] ?? 0)];
        }

        if ($preflight->ran(PreflightStep::Routes)) {
            $routes = $preflight->step(PreflightStep::Routes);
            $rows[] = ['Routes (total / flagged)', sprintf('%d / %d', $routes['count'] ?? 0, $routes['flagged'] ?? 0)];
        }

        if ($preflight->ran(PreflightStep::Translations)) {
            $rows[] = ['Missing translation keys', (string) ($preflight->step(PreflightStep::Translations)['missing'] ?? 0)];
        }

        if ($preflight->ran(PreflightStep::Composer)) {
            $facts = $preflight->json(DependenciesAudit::FACTS_FILE);
            $bySeverity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

            foreach ((array) ($facts['advisories'] ?? []) as $advisory) {
                $severity = strtolower((string) ($advisory['severity'] ?? ''));

                if (isset($bySeverity[$severity])) {
                    $bySeverity[$severity]++;
                }
            }

            $rows[] = ['Advisories (critical/high/medium/low)', implode('/', $bySeverity)];
            $rows[] = ['Abandoned packages · outdated majors', sprintf('%d · %d', count((array) ($facts['abandoned'] ?? [])), count((array) ($facts['outdated_major'] ?? [])))];
        }

        if ($riskPoints !== null) {
            $perKloc = RiskPoints::perThousandLines($riskPoints, $loc === null ? null : (int) $loc);
            $rows[] = ['Risk points (experimental)', $riskPoints.($perKloc === null ? '' : ' · '.$perKloc.' per 1k LOC')];
        }

        return $rows;
    }
}
