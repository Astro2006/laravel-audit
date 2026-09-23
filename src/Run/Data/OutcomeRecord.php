<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Data;

use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Support\Read;

final readonly class OutcomeRecord
{
    /**
     * @param  list<FindingRecord>  $findings
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $audit,
        public string $status,
        public bool $repaired,
        public string $promptHash,
        public Telemetry $telemetry,
        public array $findings,
        public array $warnings,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            audit: Read::string($data, 'audit'),
            status: Read::string($data, 'status'),
            repaired: Read::bool($data, 'repaired'),
            promptHash: Read::string($data, 'prompt_hash'),
            telemetry: Telemetry::fromArray(Read::array($data, 'telemetry')),
            findings: array_map(FindingRecord::fromArray(...), Read::arrays($data, 'findings')),
            warnings: Read::strings($data, 'warnings'),
        );
    }

    public function completed(): bool
    {
        return $this->status === 'completed';
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_unique(array_map(fn (FindingRecord $finding): string => $finding->key, $this->findings)));
    }
}
