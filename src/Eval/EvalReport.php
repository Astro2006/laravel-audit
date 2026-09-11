<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Data\Run\FindingRecord;
use Astro2006\LaravelAudit\Data\Run\OutcomeRecord;
use Astro2006\LaravelAudit\Data\Run\RunRecord;

/**
 * Compares several runs (run.json) with the expected set: recall, negative
 * control hits, stability of finding keys, format problems and cost.
 */
final class EvalReport
{
    /** @param list<RunRecord> $runs oldest first */
    public function evaluate(ExpectedSet $expected, array $runs): EvalResult
    {
        $audits = [];

        foreach ($runs as $run) {
            array_push($audits, ...$run->audits());
        }

        return new EvalResult(
            runs: array_map(fn (RunRecord $run): string => $run->runId, $runs),
            audits: array_map(fn (string $audit): AuditEvaluation => $this->evaluateAudit($expected, $runs, $audit), array_values(array_unique($audits))),
            duplicates: array_map(fn (RunRecord $run): int => $run->overlaps, $runs),
        );
    }

    /** @param list<RunRecord> $runs */
    private function evaluateAudit(ExpectedSet $expected, array $runs, string $audit): AuditEvaluation
    {
        $planted = $expected->plantedFor($audit);
        $controls = $expected->controlsFor($audit);
        $outcomes = array_values(array_filter(array_map(fn (RunRecord $run): ?OutcomeRecord => $run->outcome($audit), $runs)));

        $found = [];
        $severityOk = [];
        $controlHits = [];

        foreach ($outcomes as $outcome) {
            $matches = $this->firstMatches($planted, $outcome->findings);
            $found[] = count($matches);
            $severityOk[] = count(array_filter($matches, fn (array $pair): bool => $this->severityOk($pair)));
            $controlHits[] = count($this->firstMatches($controls, $outcome->findings));
        }

        $last = $outcomes === [] ? null : end($outcomes);

        return new AuditEvaluation(
            audit: $audit,
            planted: count($planted),
            found: $found,
            severityOk: $severityOk,
            controls: count($controls),
            controlHits: $controlHits,
            stability: Stability::of(array_map(fn (OutcomeRecord $outcome): array => $outcome->keys(), $outcomes)),
            completed: count(array_filter($outcomes, fn (OutcomeRecord $outcome): bool => $outcome->completed())),
            repaired: count(array_filter($outcomes, fn (OutcomeRecord $outcome): bool => $outcome->repaired)),
            failed: count(array_filter($outcomes, fn (OutcomeRecord $outcome): bool => ! $outcome->completed())),
            hallucinatedLocations: array_sum(array_map(
                fn (OutcomeRecord $outcome): int => count(array_filter($outcome->warnings, fn (string $warning): bool => str_contains($warning, 'possibly hallucinated'))),
                $outcomes,
            )),
            cost: TelemetryStats::of(array_map(fn (OutcomeRecord $outcome) => $outcome->telemetry, $outcomes)),
            promptHashes: array_values(array_unique(array_filter(array_map(fn (OutcomeRecord $outcome): string => $outcome->promptHash, $outcomes)))),
            extraFindings: $last === null ? [] : array_values(array_filter(
                $last->findings,
                fn (FindingRecord $finding): bool => ! $expected->explains($audit, $finding),
            )),
            missed: $this->missed($planted, $outcomes),
        );
    }

    /**
     * For each expected item, the first finding that matches it.
     *
     * @param  list<ExpectedFinding>  $expected
     * @param  list<FindingRecord>  $findings
     * @return list<array{ExpectedFinding, FindingRecord}>
     */
    private function firstMatches(array $expected, array $findings): array
    {
        $matches = [];

        foreach ($expected as $item) {
            foreach ($findings as $finding) {
                if ($item->matches($finding)) {
                    $matches[] = [$item, $finding];

                    break;
                }
            }
        }

        return $matches;
    }

    /** @param array{ExpectedFinding, FindingRecord} $pair */
    private function severityOk(array $pair): bool
    {
        [$item, $finding] = $pair;

        return $item->severityOk($finding);
    }

    /**
     * Planted problems that no run found.
     *
     * @param  list<ExpectedFinding>  $planted
     * @param  list<OutcomeRecord>  $outcomes
     * @return list<string>
     */
    private function missed(array $planted, array $outcomes): array
    {
        $findings = [];

        foreach ($outcomes as $outcome) {
            array_push($findings, ...$outcome->findings);
        }

        return array_values(array_map(
            fn (ExpectedFinding $item): string => $item->id.' ('.$item->check.', '.$item->target.')',
            array_filter($planted, fn (ExpectedFinding $item): bool => $this->firstMatches([$item], $findings) === []),
        ));
    }

    public function render(EvalResult $result): string
    {
        $lines = [
            '# Eval '.date('Y-m-d H:i'),
            '',
            'Runs: '.implode(', ', $result->runs),
            '',
            '| Audit | Recall | Found per run | Severity ok | Control hits | Jaccard | In all runs | Completed / repaired / failed | Hallucinated locations | Cost mean (max) | Prompt |',
            '|---|---|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($result->audits as $audit) {
            $recall = $audit->recall();
            $lines[] = sprintf(
                '| %s | %s | %s / %d | %s | %s | %s | %s | %d / %d / %d | %d | $%s ($%s) | %s |',
                $audit->audit,
                $recall === null ? '–' : $recall.' %',
                implode(', ', $audit->found),
                $audit->planted,
                implode(', ', $audit->severityOk),
                implode(', ', $audit->controlHits),
                $audit->stability->jaccard ?? '–',
                $audit->stability->inAllRuns ?? '–',
                $audit->completed,
                $audit->repaired,
                $audit->failed,
                $audit->hallucinatedLocations,
                number_format($audit->cost->costMean, 2),
                number_format($audit->cost->costMax, 2),
                implode(', ', $audit->promptHashes),
            );
        }

        $lines = [
            ...$lines,
            '',
            'Possible duplicates per run: '.implode(', ', $result->duplicates),
            'Negative control hits per run (all audits): '.implode(', ', $result->controlHitsTotal()),
            '',
        ];

        foreach ($result->audits as $audit) {
            if ($audit->missed !== []) {
                $lines = [...$lines, '## Missed in every run — '.$audit->audit, '', ...array_map(fn (string $item): string => '- '.$item, $audit->missed), ''];
            }

            if ($audit->extraFindings !== []) {
                $lines = [
                    ...$lines,
                    '## Other findings (last run) — '.$audit->audit,
                    '',
                    'Rate each: useful / correct but unimportant / wrong.',
                    '',
                    '| ID | Check | Severity | Title | Locations | Rating |',
                    '|---|---|---|---|---|---|',
                    ...array_map(fn (FindingRecord $finding): string => sprintf(
                        '| %s | %s | %s | %s | %s | |',
                        $finding->id,
                        $finding->check,
                        $finding->severity,
                        str_replace('|', '\\|', $finding->title),
                        implode(', ', $finding->locations),
                    ), $audit->extraFindings),
                    '',
                ];
            }
        }

        return implode("\n", $lines)."\n";
    }
}
