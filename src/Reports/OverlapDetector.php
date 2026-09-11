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
     * Findings of different audits, or of different tasks of one audit,
     * that point to the same place.
     *
     * @param  array<string, list<Finding>>  $findingsByAudit
     * @param  array<string, array<string, string>>  $findingTasks  audit => finding ID => task key
     * @return list<Overlap>
     */
    public function detect(array $findingsByAudit, array $findingTasks = []): array
    {
        $overlaps = [];
        $audits = [];

        foreach ($findingsByAudit as $audit => $findings) {
            $audits[] = [$audit, $findings];

            foreach ($findings as $i => $findingA) {
                foreach (array_slice($findings, $i + 1) as $findingB) {
                    $taskA = $findingTasks[$audit][$findingA->id] ?? null;
                    $taskB = $findingTasks[$audit][$findingB->id] ?? null;

                    if ($taskA === null || $taskB === null || $taskA === $taskB) {
                        continue;
                    }

                    $shared = $this->sharedLocation($findingA, $findingB);

                    if ($shared !== null) {
                        $overlaps[] = new Overlap($audit, $findingA, $audit, $findingB, $shared);
                    }
                }
            }
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
