<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class EvalResult implements Arrayable
{
    /**
     * @param  list<string>  $runs
     * @param  list<AuditEvaluation>  $audits
     * @param  list<int>  $duplicates  possible duplicates per run
     */
    public function __construct(
        public array $runs,
        public array $audits,
        public array $duplicates,
    ) {}

    public function audit(string $audit): ?AuditEvaluation
    {
        foreach ($this->audits as $evaluation) {
            if ($evaluation->audit === $audit) {
                return $evaluation;
            }
        }

        return null;
    }

    /** @return list<int> negative control hits per run, over all audits */
    public function controlHitsTotal(): array
    {
        return array_map(
            fn (int $index): int => array_sum(array_map(fn (AuditEvaluation $audit): int => $audit->controlHitsInRun($index), $this->audits)),
            array_keys($this->runs),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'runs' => $this->runs,
            'audits' => array_map(fn (AuditEvaluation $audit): array => $audit->toArray(), $this->audits),
            'duplicates' => $this->duplicates,
            'control_hits_total' => $this->controlHitsTotal(),
        ];
    }
}
