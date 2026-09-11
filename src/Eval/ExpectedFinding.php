<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Data\Run\FindingRecord;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Support\Read;

/**
 * A planted problem or a negative control in the fixture app.
 */
final readonly class ExpectedFinding
{
    public function __construct(
        public string $id,
        public string $audit,
        public string $check,
        public string $target,
        public ?Severity $minSeverity = null,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $route = Read::nullableString($data, 'route');
        $package = Read::nullableString($data, 'package');

        return new self(
            id: Read::string($data, 'id', '?'),
            audit: Read::string($data, 'audit'),
            check: Read::string($data, 'check'),
            target: match (true) {
                $route !== null => 'route:'.$route,
                $package !== null => 'package:'.strtolower($package),
                default => Read::string($data, 'file'),
            },
            minSeverity: Severity::tryFrom(Read::string($data, 'min_severity')),
        );
    }

    public function matches(FindingRecord $finding): bool
    {
        return $finding->check === $this->check && in_array($this->target, $finding->targets, true);
    }

    public function severityOk(FindingRecord $finding): bool
    {
        $severity = Severity::tryFrom($finding->severity);

        return $this->minSeverity === null || ($severity !== null && $severity->rank() >= $this->minSeverity->rank());
    }
}
