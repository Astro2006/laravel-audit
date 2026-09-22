<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Install;

use Astro2006\LaravelAudit\Install\ComposerPreview;
use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Install\PlannedPackage;

/**
 * Runs the composer command as a dry run and lists what it would change
 * besides the planned packages.
 */
final class PreviewComposerChanges
{
    /** @return list<string> */
    public function handle(InstallPlan $plan, PlanExecutor $executor): array
    {
        $command = $executor->composerCommand($plan, dryRun: true);

        if ($command === null) {
            return [];
        }

        return (new ComposerPreview)->sideEffects(
            $executor->capture($command),
            array_map(fn (PlannedPackage $package): string => $package->name, $plan->composer()),
        );
    }
}
