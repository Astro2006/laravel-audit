<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Console\AuditConsole;

final readonly class RunContext
{
    public function __construct(
        public RunPlan $plan,
        public AuditRun $run,
        public RunManifest $manifest,
        public AuditOptions $options,
        public AuditConsole $console,
    ) {}
}
