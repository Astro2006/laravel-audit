<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Support\AuditConfig;

final readonly class VerifyPolicy
{
    /**
     * @param  int  $threshold  severity rank minus confidence rank from which a finding is verified
     * @param  Severity  $from  severity from which every finding is verified unless confirmed
     */
    public function __construct(
        private int $threshold = 2,
        private Severity $from = Severity::Medium,
    ) {}

    public static function fromConfig(AuditConfig $config): self
    {
        return new self(
            max(0, $config->int('agent.verify.threshold')),
            Severity::tryFrom($config->string('agent.verify.from')) ?? Severity::Medium,
        );
    }

    public function needsVerify(Finding $finding): bool
    {
        if ($finding->severity->rank() - $finding->confidence->rank() >= $this->threshold) {
            return true;
        }

        return $finding->severity->rank() >= $this->from->rank()
            && $finding->confidence !== Confidence::Confirmed;
    }
}
