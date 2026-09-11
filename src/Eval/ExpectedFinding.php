<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Enums\Severity;

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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $target = match (true) {
            isset($data['route']) => 'route:'.$data['route'],
            isset($data['package']) => 'package:'.strtolower((string) $data['package']),
            default => (string) ($data['file'] ?? ''),
        };

        return new self(
            id: (string) ($data['id'] ?? '?'),
            audit: (string) ($data['audit'] ?? ''),
            check: (string) ($data['check'] ?? ''),
            target: $target,
            minSeverity: isset($data['min_severity']) ? Severity::tryFrom((string) $data['min_severity']) : null,
        );
    }

    /**
     * @param  array{check: string, targets: list<string>}  $finding  a finding from run.json
     */
    public function matches(array $finding): bool
    {
        return $finding['check'] === $this->check && in_array($this->target, $finding['targets'], true);
    }

    /** @param array{severity: string} $finding */
    public function severityOk(array $finding): bool
    {
        $severity = Severity::tryFrom($finding['severity']);

        return $this->minSeverity === null || ($severity !== null && $severity->rank() >= $this->minSeverity->rank());
    }
}
