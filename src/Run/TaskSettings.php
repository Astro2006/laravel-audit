<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Support\AuditConfig;

final readonly class TaskSettings
{
    public const string SUMMARY_GENERATED = 'generated';

    public const string SUMMARY_AGENT = 'agent';

    /**
     * @param  string  $summaryMode  generated: the harness writes each summary; agent: a summary task does
     * @param  bool  $verify  a verify job checks the findings of every task (see VerifyPolicy)
     */
    public function __construct(
        public int $maxFindingsPerTask = 8,
        public string $summaryMode = self::SUMMARY_GENERATED,
        public bool $verify = true,
    ) {}

    public static function fromConfig(AuditConfig $config): self
    {
        return new self(
            max(1, $config->int('max_findings_per_task')),
            $config->string('summary') === self::SUMMARY_AGENT ? self::SUMMARY_AGENT : self::SUMMARY_GENERATED,
            $config->bool('agent.verify.enabled'),
        );
    }
}
