<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Closure;

final readonly class AgentJob
{
    /**
     * @param  AgentRequest|Closure(array<string, TaskOutcome>): ?AgentRequest  $request  null = nothing to do
     * @param  list<RequiredFinding>  $required
     * @param  list<string>  $dependsOn  keys of jobs that must finish first (any status)
     */
    public function __construct(
        public string $key,
        public ?AuditType $audit,
        public ReportSpec $spec,
        public AgentRequest|Closure $request,
        public string $promptHash,
        public array $required = [],
        public array $dependsOn = [],
    ) {}

    /** @param array<string, TaskOutcome> $finished */
    public function request(array $finished): ?AgentRequest
    {
        return $this->request instanceof AgentRequest ? $this->request : ($this->request)($finished);
    }
}
