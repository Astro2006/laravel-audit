<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Services;

use Astro2006\LaravelAudit\Actions\Install\ExecuteInstallPlanAction;
use Astro2006\LaravelAudit\Actions\Install\PreviewComposerChangesAction;
use Astro2006\LaravelAudit\Actions\Install\PublishConfigAction;
use Astro2006\LaravelAudit\Actions\Install\VerifyInstallAction;
use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Console\Output\InstallConsole;
use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\InstallPlanner;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Symfony\Component\Process\Process as SymfonyProcess;

final class InstallService
{
    public const int SUCCESS = 0;

    public const int FAILURE = 1;

    public function __construct(
        private readonly AuditConfig $config,
        private readonly PublishConfigAction $publishConfig,
        private readonly PreviewComposerChangesAction $previewComposerChanges,
        private readonly ExecuteInstallPlanAction $executeInstallPlan,
        private readonly VerifyInstallAction $verifyInstall,
    ) {}

    /** @param bool $yes install the plan without asking (except upgrades) */
    public function run(bool $dryRun, bool $yes, InstallConsole $console): int
    {
        $this->publishConfig->handle();
        $project = ProjectInspector::fromConfig($this->config);
        $outputPath = $this->config->string('output_path');
        $stubLevel = $this->config->int('preflight.phpstan.stub_level');

        $console->header();

        $driver = (new DriverRegistry($this->config, $project))->resolve();
        $plan = (new InstallPlanner($project, $this->config, DatabaseGuard::forApp($project), $driver))->plan();
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
