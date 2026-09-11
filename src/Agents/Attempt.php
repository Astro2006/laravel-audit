<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Illuminate\Contracts\Process\InvokedProcess;

final readonly class Attempt
{
    /**
     * @param  float  $startedAt  microtime of this attempt
     * @param  float  $jobStartedAt  microtime of the job's first attempt
     */
    public function __construct(
        public AgentJob $job,
        public AgentRequest $request,
        public InvokedProcess $process,
        public float $startedAt,
        public bool $repair,
        public Telemetry $telemetry,
        public float $jobStartedAt,
    ) {}
}
