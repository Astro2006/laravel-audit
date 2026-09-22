<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Services;

use Astro2006\LaravelAudit\Actions\Install\ExecuteInstallPlan;
use Astro2006\LaravelAudit\Actions\Install\PreviewComposerChanges;
use Astro2006\LaravelAudit\Actions\Install\VerifyInstall;
use Astro2006\LaravelAudit\Console\InstallConsole;
use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\InstallPlanner;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\Read;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * `audit:install` from start to end: plan what is missing, ask, install,
 * check what really got installed.
 */
final class InstallService
{
    public const int SUCCESS = 0;

    public const int FAILURE = 1;

    public function __construct(
        private readonly HarnessFactory $factory,
        private readonly PreviewComposerChanges $previewComposerChanges,
        private readonly ExecuteInstallPlan $executeInstallPlan,
        private readonly VerifyInstall $verifyInstall,
    ) {}

    /** @param bool $yes install the plan without asking (except upgrades) */
    public function run(bool $dryRun, bool $yes, InstallConsole $console): int
    {
        $project = $this->factory->project();
        $outputPath = Read::string($this->factory->allConfig(), 'output_path', storage_path('audit'));
        $stubLevel = (int) $this->factory->config('preflight.phpstan.stub_level', 5);

        $console->header();

        $driver = $this->factory->driver($project);
        $plan = (new InstallPlanner($project, $this->factory->allConfig(), $this->factory->databaseGuard($project), $driver))->plan();
        $executor = new PlanExecutor($project);

        $console->plan($plan, $executor, $stubLevel);

        if ($plan->isEmpty()) {
            $console->line('  <fg=green>✔</> '.$console->messages->get('install.nothing_missing'));
            $result = $this->verifyInstall->handle($project, $outputPath, $plan, [], ['failed' => [], 'manual' => []], $stubLevel);
            $console->result($result, $project, $driver);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $console->note('install.dry_run');

            return self::SUCCESS;
        }

        if (! $console->interactive && ! $yes) {
            $console->note('install.not_interactive', 'yellow');

            return $plan->packages === [] ? self::SUCCESS : self::FAILURE;
        }

        $selected = $plan->only($yes ? $this->regularPackages($plan) : $console->choosePackages($plan));
        $declined = array_values(array_diff($plan->names(), $selected->names()));

        if ($selected->composer() !== []) {
            $effects = $this->previewComposerChanges->handle($selected, $executor);

            if ($effects !== []) {
                $console->sideEffects($effects);

                if (! $yes && ! $console->confirmSideEffects()) {
                    return self::FAILURE;
                }
            }
        }

        $executed = $this->executeInstallPlan->handle(
            $selected,
            $executor,
            $stubLevel,
            $console->interactive && SymfonyProcess::isTtySupported(),
            $console->command(...),
            $console->write(...),
        );

        $result = $this->verifyInstall->handle($project, $outputPath, $selected, $declined, $executed, $stubLevel);
        $console->result($result, $project, $driver);

        return $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> every planned package that is not an upgrade */
    private function regularPackages(InstallPlan $plan): array
    {
        $names = [];

        foreach ($plan->packages as $package) {
            if (! $package->isUpgrade()) {
                $names[] = $package->name;
            }
        }

        return $names;
    }
}
