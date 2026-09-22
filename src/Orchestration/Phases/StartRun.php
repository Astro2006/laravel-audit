<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Orchestration\RunContext;
use Astro2006\LaravelAudit\Orchestration\RunPlan;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\GitInfo;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\PackageVersion;

/**
 * Creates the run folder and writes the first run.md.
 */
final class StartRun
{
    public function __construct(private readonly AuditConfig $config) {}

    public function handle(RunPlan $plan, AuditOptions $options, AuditConsole $console): RunContext
    {
        $run = AuditRun::create($this->config->string('output_path'));
        $manifest = new RunManifest($run, $plan->selection, GitInfo::read($plan->project->basePath), $plan->driver->name(), PackageVersion::get());
        $manifest->write();

        return new RunContext($plan, $run, $manifest, $options, $console);
    }
}
