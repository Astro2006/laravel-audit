<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Install;

use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\InstallResult;
use Astro2006\LaravelAudit\Install\PlannedPackage;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Prepares the output folder and checks what really got installed.
 */
final class VerifyInstall
{
    /**
     * @param  list<string>  $declined
     * @param  array{failed: list<string>, manual: list<string>}  $executed
     */
    public function handle(ProjectInspector $project, string $outputPath, InstallPlan $plan, array $declined, array $executed, int $stubLevel): InstallResult
    {
        AuditRun::ensureOutputRoot($outputPath);

        // Re-check from the files: composer.lock and node_modules tell the
        // truth, independent of what this process has loaded.
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
            failed: $executed['failed'],
            manualCommands: $executed['manual'],
            manualKeys: $plan->manual,
        );
    }
}
