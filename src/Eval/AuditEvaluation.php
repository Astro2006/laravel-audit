<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Run\Data\FindingRecord;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Eval result of one audit across all runs.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class AuditEvaluation implements Arrayable
{
    /**
     * @param  list<int>  $found  planted problems found, per run
     * @param  list<int>  $severityOk  of those, with at least the expected severity
     * @param  list<int>  $controlHits  negative controls reported, per run
     * @param  list<string>  $promptHashes
     * @param  list<FindingRecord>  $extraFindings  findings of the last run that are neither planted nor controls
     * @param  list<string>  $missed  planted problems no run found
     */
    public function __construct(
        public string $audit,
        public int $planted,
        public array $found,
        public array $severityOk,
        public int $controls,
        public array $controlHits,
        public Stability $stability,
        public int $completed,
        public int $repaired,
        public int $failed,
        public int $hallucinatedLocations,
        public TelemetryStats $cost,
        public array $promptHashes,
        public array $extraFindings,
        public array $missed,
    ) {}

    public function recall(): ?float
    {
        if ($this->planted === 0 || $this->found === []) {
            return null;
        }

        return round(array_sum($this->found) / count($this->found) / $this->planted * 100, 1);
    }

    public function controlHitsInRun(int $index): int
    {
        return (int) array_sum(array_slice($this->controlHits, $index, 1));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'audit' => $this->audit,
            'planted' => $this->planted,
            'found' => $this->found,
            'recall' => $this->recall(),
            'severity_ok' => $this->severityOk,
            'controls' => $this->controls,
            'control_hits' => $this->controlHits,
            'stability' => $this->stability->toArray(),
            'format' => [
                'completed' => $this->completed,
                'repaired' => $this->repaired,
                'failed' => $this->failed,
                'hallucinated_locations' => $this->hallucinatedLocations,
            ],
            'cost' => $this->cost->toArray(),
            'prompt_hashes' => $this->promptHashes,
            'extra_findings' => array_map(fn (FindingRecord $finding): array => [
                'id' => $finding->id,
                'check' => $finding->check,
                'severity' => $finding->severity,
                'title' => $finding->title,
                'locations' => $finding->locations,
            ], $this->extraFindings),
            'missed' => $this->missed,
        ];
    }
}
