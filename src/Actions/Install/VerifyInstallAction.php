<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Install;

use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\InstallResult;
use Astro2006\LaravelAudit\Install\PlannedPackage;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Arr;

final class VerifyInstallAction
{
    /**
     * @param  list<string>  $declined
     * @param  array{failed: list<string>, manual: list<string>}  $executed
     */
    public function handle(ProjectInspector $project, string $outputPath, InstallPlan $plan, array $declined, array $executed, int $stubLevel): InstallResult
    {
        AuditRun::ensureOutputRoot($outputPath);

        $fresh = new ProjectInspector($project->basePath);
        $installed = array_filter($plan->packages, fn (PlannedPackage $package): bool => $package->manager === 'npm'
            ? $fresh->hasNodeModule($package->name)
            : $fresh->isInstalled($package->name));

        return new InstallResult(
            outputPath: $outputPath,
            installed: count($installed),
            stubCreated: in_array('phpstan-stub', $plan->setup, true) && $fresh->phpstanConfigPath() !== null,
            stubLevel: $stubLevel,
            declined: $declined,
            failed: Arr::get($executed, 'failed'),
            manualCommands: Arr::get($executed, 'manual'),
            manualKeys: $plan->manual,
        );
    }
}
