<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\RunManifest;

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
