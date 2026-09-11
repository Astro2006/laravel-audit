<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Run;

use Astro2006\LaravelAudit\Support\Read;

/**
 * A run as stored in run.json.
 */
final readonly class RunRecord
{
    /** @param list<OutcomeRecord> $outcomes */
    public function __construct(
        public string $runId,
        public int $overlaps,
        public array $outcomes,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Read::string($data, 'run_id', '?'),
            Read::int($data, 'overlaps'),
            array_map(OutcomeRecord::fromArray(...), Read::arrays($data, 'outcomes')),
        );
    }

    public function outcome(string $audit): ?OutcomeRecord
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->audit === $audit) {
                return $outcome;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function audits(): array
    {
        return array_map(fn (OutcomeRecord $outcome): string => $outcome->audit, $this->outcomes);
    }
}
