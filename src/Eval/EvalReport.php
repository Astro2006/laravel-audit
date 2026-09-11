<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

/**
 * Compares several runs (run.json) with the expected set: recall, negative
 * control hits, stability of finding keys, format problems and cost.
 */
final class EvalReport
{
    /**
     * @param  list<array<string, mixed>>  $runs  decoded run.json files, oldest first
     * @return array<string, mixed>
     */
    public function evaluate(ExpectedSet $expected, array $runs): array
    {
        $audits = [];

        foreach ($runs as $run) {
            foreach (array_keys((array) ($run['outcomes'] ?? [])) as $audit) {
                $audits[$audit] = true;
            }
        }

        $result = ['runs' => array_map(fn (array $run): string => (string) ($run['run_id'] ?? '?'), $runs), 'audits' => []];

        foreach (array_keys($audits) as $audit) {
            $result['audits'][$audit] = $this->evaluateAudit($expected, $runs, $audit);
        }

        $result['duplicates'] = array_map(fn (array $run): int => (int) ($run['overlaps'] ?? 0), $runs);
        $result['control_hits_total'] = array_map(
            fn (int $index): int => array_sum(array_map(fn (array $audit): int => $audit['control_hits'][$index] ?? 0, $result['audits'])),
            array_keys($runs),
        );

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function evaluateAudit(ExpectedSet $expected, array $runs, string $audit): array
    {
        $planted = $expected->plantedFor($audit);
        $controls = $expected->controlsFor($audit);
        $found = [];
        $severityOk = [];
        $controlHits = [];
        $keySets = [];
        $telemetry = [];
        $format = ['completed' => 0, 'repaired' => 0, 'failed' => 0, 'hallucinated_locations' => 0];
        $hashes = [];
        $extra = [];

        foreach ($runs as $index => $run) {
            $outcome = $run['outcomes'][$audit] ?? null;

            if ($outcome === null) {
                continue;
            }

            $findings = (array) ($outcome['findings'] ?? []);
            $format[$outcome['status'] === 'completed' ? 'completed' : 'failed']++;
            $format['repaired'] += ($outcome['repaired'] ?? false) ? 1 : 0;
            $format['hallucinated_locations'] += count(array_filter((array) ($outcome['warnings'] ?? []), fn (string $warning): bool => str_contains($warning, 'possibly hallucinated')));
            $hashes[(string) ($outcome['prompt_hash'] ?? '')] = true;
            $telemetry[] = (array) ($outcome['telemetry'] ?? []);
            $keySets[] = array_values(array_unique(array_column($findings, 'key')));

            $found[$index] = 0;
            $severityOk[$index] = 0;

            foreach ($planted as $item) {
                foreach ($findings as $finding) {
                    if ($item->matches($finding)) {
                        $found[$index]++;
                        $severityOk[$index] += $item->severityOk($finding) ? 1 : 0;

                        continue 2;
                    }
                }
            }

            $controlHits[$index] = 0;

            foreach ($controls as $item) {
                foreach ($findings as $finding) {
                    if ($item->matches($finding)) {
                        $controlHits[$index]++;

                        continue 2;
                    }
                }
            }

            if ($index === array_key_last($runs)) {
                foreach ($findings as $finding) {
                    $matched = false;

                    foreach ([...$planted, ...$controls] as $item) {
                        $matched = $matched || $item->matches($finding);
                    }

                    if (! $matched) {
                        $extra[] = $finding;
                    }
                }
            }
        }

        $total = count($planted);

        return [
            'planted' => $total,
            'found' => array_values($found),
            'recall' => $total === 0 || $found === [] ? null : round(array_sum($found) / count($found) / $total * 100, 1),
            'severity_ok' => array_values($severityOk),
            'controls' => count($controls),
            'control_hits' => array_values($controlHits),
            'stability' => $this->stability($keySets),
            'format' => $format,
            'cost' => $this->aggregate($telemetry),
            'prompt_hashes' => array_keys(array_filter($hashes, fn ($value, $key): bool => $key !== '', ARRAY_FILTER_USE_BOTH)),
            'extra_findings' => $extra,
            'missed' => $this->missed($planted, $runs, $audit),
        ];
    }

    /**
     * Mean pairwise Jaccard index of the finding keys, and the share of keys
     * found in every run.
     *
     * @param  list<list<string>>  $keySets
     * @return array{jaccard: float|null, in_all_runs: float|null}
     */
    public function stability(array $keySets): array
    {
        if (count($keySets) < 2) {
            return ['jaccard' => null, 'in_all_runs' => null];
        }

        $scores = [];

        foreach ($keySets as $i => $a) {
            foreach (array_slice($keySets, $i + 1) as $b) {
                $union = count(array_unique([...$a, ...$b]));
                $scores[] = $union === 0 ? 1.0 : count(array_intersect($a, $b)) / $union;
            }
        }

        $union = array_unique(array_merge(...$keySets));
        $intersection = array_intersect(...$keySets);

        return [
            'jaccard' => round(array_sum($scores) / count($scores), 2),
            'in_all_runs' => $union === [] ? null : round(count($intersection) / count($union), 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $telemetry
     * @return array<string, array{mean: float, max: float}>
     */
    private function aggregate(array $telemetry): array
    {
        $result = [];

        foreach (['cost_usd', 'input_tokens', 'output_tokens', 'turns', 'duration_s'] as $field) {
            $values = array_map(fn (array $item): float => (float) ($item[$field] ?? 0), $telemetry);
            $result[$field] = $values === []
                ? ['mean' => 0.0, 'max' => 0.0]
                : ['mean' => round(array_sum($values) / count($values), 2), 'max' => round(max($values), 2)];
        }

        return $result;
    }

    /**
     * Planted problems that no run found.
     *
     * @param  list<ExpectedFinding>  $planted
     * @param  list<array<string, mixed>>  $runs
     * @return list<string>
     */
    private function missed(array $planted, array $runs, string $audit): array
    {
        $missed = [];

        foreach ($planted as $item) {
            foreach ($runs as $run) {
                foreach ((array) ($run['outcomes'][$audit]['findings'] ?? []) as $finding) {
                    if ($item->matches($finding)) {
                        continue 3;
                    }
                }
            }

            $missed[] = $item->id.' ('.$item->check.', '.$item->target.')';
        }

        return $missed;
    }

    /** @param array<string, mixed> $result */
    public function render(array $result): string
    {
        $lines = ['# Eval '.date('Y-m-d H:i'), '', 'Runs: '.implode(', ', $result['runs']), ''];

        $lines[] = '| Audit | Recall | Found per run | Severity ok | Control hits | Jaccard | In all runs | Completed / repaired / failed | Hallucinated locations | Cost mean (max) | Prompt |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';

        foreach ($result['audits'] as $audit => $data) {
            $lines[] = sprintf(
                '| %s | %s | %s / %d | %s | %s | %s | %s | %d / %d / %d | %d | $%s ($%s) | %s |',
                $audit,
                $data['recall'] === null ? '–' : $data['recall'].' %',
                implode(', ', $data['found']),
                $data['planted'],
                implode(', ', $data['severity_ok']),
                implode(', ', $data['control_hits']),
                $data['stability']['jaccard'] ?? '–',
                $data['stability']['in_all_runs'] ?? '–',
                $data['format']['completed'],
                $data['format']['repaired'],
                $data['format']['failed'],
                $data['format']['hallucinated_locations'],
                number_format($data['cost']['cost_usd']['mean'], 2),
                number_format($data['cost']['cost_usd']['max'], 2),
                implode(', ', $data['prompt_hashes']),
            );
        }

        $lines[] = '';
        $lines[] = 'Possible duplicates per run: '.implode(', ', $result['duplicates']);
        $lines[] = 'Negative control hits per run (all audits): '.implode(', ', $result['control_hits_total']);
        $lines[] = '';

        foreach ($result['audits'] as $audit => $data) {
            if ($data['missed'] !== []) {
                $lines[] = '## Missed in every run — '.$audit;
                $lines[] = '';
                array_push($lines, ...array_map(fn (string $item): string => '- '.$item, $data['missed']));
                $lines[] = '';
            }

            if ($data['extra_findings'] !== []) {
                $lines[] = '## Other findings (last run) — '.$audit;
                $lines[] = '';
                $lines[] = 'Rate each: useful / correct but unimportant / wrong.';
                $lines[] = '';
                $lines[] = '| ID | Check | Severity | Title | Locations | Rating |';
                $lines[] = '|---|---|---|---|---|---|';

                foreach ($data['extra_findings'] as $finding) {
                    $lines[] = sprintf('| %s | %s | %s | %s | %s | |', $finding['id'], $finding['check'], $finding['severity'], str_replace('|', '\\|', $finding['title']), implode(', ', $finding['locations']));
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
