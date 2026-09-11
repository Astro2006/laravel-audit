<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Illuminate\Contracts\Process\InvokedProcess;

/**
 * One running agent process: the first answer or the repair pass.
 */
final readonly class Attempt
{
    public function __construct(
        public AgentJob $job,
        public InvokedProcess $process,
        public float $startedAt,
        public bool $repair,
        public Telemetry $telemetry,
    ) {}
}
