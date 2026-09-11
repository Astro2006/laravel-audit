<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Preflight\PreflightSummary;

/**
 * Deterministic numbers straight from the preflight.
 */
final class Metrics
{
    /** @return list<Metric> */
    public function rows(PreflightSummary $preflight, ?float $riskPoints = null): array
    {
        $steps = $preflight->steps;
        $loc = $steps->linesOfCode()?->loc;
        $rows = [new Metric('LOC (app/)', $loc === null ? '–' : (string) $loc)];

        $tests = $steps->tests();

        if ($tests !== null) {
            $rows[] = new Metric('Tests (passed / failed / skipped)', sprintf('%d / %d / %d', $tests->passed, $tests->failures(), $tests->skipped));
            $rows[] = new Metric('Line coverage', $tests->coveragePct === null ? '–' : $tests->coveragePct.' %');
        }

        $browser = $steps->browser();

        if ($browser !== null) {
            $rows[] = new Metric('Browser tests (passed / failed)', sprintf('%d / %d', $browser->passed, $browser->failures()));
        }

        $phpstan = $steps->phpstan();

        if ($phpstan !== null) {
            $rows[] = new Metric('PHPStan errors (active / baselined) · level', sprintf('%d / %d · %s', $phpstan->errors, $phpstan->baselined, $phpstan->level ?? '?'));
        }

        $pint = $steps->pint();

        if ($pint !== null) {
            $rows[] = new Metric('Pint files with issues', (string) $pint->filesWithIssues);
        }

        $routes = $steps->routes();

        if ($routes !== null) {
            $rows[] = new Metric('Routes (total / flagged)', sprintf('%d / %d', $routes->count, $routes->flagged));
        }

        $translations = $steps->translations();

        if ($translations !== null) {
            $rows[] = new Metric('Missing translation keys', (string) $translations->missing);
        }

        $facts = $preflight->dependencyFacts();

        if ($facts !== null) {
            $rows[] = new Metric('Advisories (critical/high/medium/low)', implode('/', $facts->advisoriesBySeverity()));
            $rows[] = new Metric('Abandoned packages · outdated majors', sprintf('%d · %d', count($facts->abandoned), count($facts->outdatedMajor)));
        }

        if ($riskPoints !== null) {
            $perKloc = RiskPoints::perThousandLines($riskPoints, $loc);
            $rows[] = new Metric('Risk points (experimental)', $riskPoints.($perKloc === null ? '' : ' · '.$perKloc.' per 1k LOC'));
        }

        return $rows;
    }
}
