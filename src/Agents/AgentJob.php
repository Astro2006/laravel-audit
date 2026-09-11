<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

final readonly class AgentJob
{
    /** @param list<RequiredFinding> $required */
    public function __construct(
        public Audit $audit,
        public AgentRequest $request,
        public string $promptHash,
        public array $required = [],
    ) {}
}
