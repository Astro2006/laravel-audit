<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Audit;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\PreflightRunner;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Runs every preflight step the selected audits need and shows each result
 * as it arrives.
 */
final class RunPreflight
{
    public function __construct(private readonly HarnessFactory $factory) {}

    public function handle(AuditRun $run, RunSelection $selection, ProjectInspector $project, DatabaseGuard $guard, AuditConsole $console): PreflightContext
    {
        $context = new PreflightContext($project, $run->preflightDir(), $run->logsDir(), $this->factory->allConfig(), $selection, $this->factory->childEnvironment($project));

        (new PreflightRunner($this->factory->steps($project, $guard)))->run(
            $context,
            $console->stepFinished(...),
            $console->stepStarted(...),
        );

        return $context;
    }
}
