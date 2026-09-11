<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\RequiredFinding;
use Closure;

/**
 * One agent call. The request may be built lazily, once the jobs it
 * depends on have finished (their output becomes part of the prompt).
 */
final readonly class AgentJob
{
    /**
     * @param  AgentRequest|Closure(array<string, TaskOutcome>): AgentRequest  $request
     * @param  list<RequiredFinding>  $required
     * @param  list<string>  $dependsOn  keys of jobs that must finish first (any status)
     */
    public function __construct(
        public string $key,
        public AuditType $audit,
        public ReportSpec $spec,
        public AgentRequest|Closure $request,
        public string $promptHash,
        public array $required = [],
        public array $dependsOn = [],
    ) {}

    /** @param array<string, TaskOutcome> $finished */
    public function request(array $finished): AgentRequest
    {
        return $this->request instanceof AgentRequest ? $this->request : ($this->request)($finished);
    }
}
