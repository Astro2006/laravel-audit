<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Lists findings from different audits that point at the same place:
 * overlapping lines in a file, the same route or the same package.
 * Purely deterministic; nothing is merged or dropped.
 */
final class OverlapDetector
{
    /**
     * @param  array<string, list<Finding>>  $findingsByAudit
     * @return list<Overlap>
     */
    public function detect(array $findingsByAudit): array
    {
        $overlaps = [];
        $audits = [];

        foreach ($findingsByAudit as $audit => $findings) {
            $audits[] = [$audit, $findings];
        }

        foreach ($audits as $i => [$auditA, $findingsA]) {
            foreach (array_slice($audits, $i + 1) as [$auditB, $findingsB]) {
                foreach ($findingsA as $findingA) {
                    foreach ($findingsB as $findingB) {
                        $shared = $this->sharedLocation($findingA, $findingB);

                        if ($shared !== null) {
                            $overlaps[] = new Overlap($auditA, $findingA, $auditB, $findingB, $shared);
                        }
                    }
                }
            }
        }

        return $overlaps;
    }

    private function sharedLocation(Finding $a, Finding $b): ?Location
    {
        foreach ($a->locations as $locationA) {
            foreach ($b->locations as $locationB) {
                if ($locationA->overlaps($locationB)) {
                    return $locationA;
                }
            }
        }

        return null;
    }
}
