<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Run\AuditOptions;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\GitInfo;
use Astro2006\LaravelAudit\Run\RunContext;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunPlan;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\PackageVersion;

final class StartRun
{
    public function __construct(private readonly AuditConfig $config) {}

    public function handle(RunPlan $plan, AuditOptions $options, AuditConsole $console): RunContext
    {
        [$run, $manifest] = $this->create($plan);

        return new RunContext($plan, $run, $manifest, $options, $console);
    }

    /** @return array{AuditRun, RunManifest} */
    public function create(RunPlan $plan): array
    {
        $run = AuditRun::create($this->config->string('output_path'));
        $manifest = self::manifest($run, $plan);
        $manifest->write();

        return [$run, $manifest];
    }

    public static function manifest(AuditRun $run, RunPlan $plan): RunManifest
    {
        return new RunManifest($run, $plan->selection, GitInfo::read($plan->project->basePath), $plan->driver->name(), PackageVersion::get());
    }
}
