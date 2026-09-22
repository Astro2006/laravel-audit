<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Install;

use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Install\ShellCommand;
use Closure;

/**
 * Installs the composer and npm packages of the plan, then runs its setup
 * steps.
 */
final class ExecuteInstallPlan
{
    /**
     * @param  bool  $tty  whether interactive setup (boost:install) can run here
     * @param  Closure(string): void  $announce  called with each command before it runs
     * @param  Closure(string): void  $stream  receives the command output
     * @return array{failed: list<string>, manual: list<string>}
     */
    public function handle(InstallPlan $plan, PlanExecutor $executor, int $stubLevel, bool $tty, Closure $announce, Closure $stream): array
    {
        $failed = [];
        $manual = [];

        foreach ([$executor->composerCommand($plan), $executor->npmCommand($plan)] as $command) {
            if ($command !== null && ! $this->run($executor, $command, $announce, $stream)) {
                $failed[] = ShellCommand::display($command);
            }
        }

        foreach ($plan->setup as $step) {
            if ($step === 'phpstan-stub') {
                $executor->writeStub($stubLevel);

                continue;
            }

            $command = $executor->setupCommand($step);

            if ($command === null) {
                continue;
            }

            if ($step === 'boost' && ! $tty) {
                $manual[] = ShellCommand::display($command);

                continue;
            }

            if (! $this->run($executor, $command, $announce, $stream, tty: $step === 'boost')) {
                $failed[] = ShellCommand::display($command);
            }
        }

        return ['failed' => $failed, 'manual' => $manual];
    }

    /**
     * @param  list<string>  $command
     * @param  Closure(string): void  $announce
     * @param  Closure(string): void  $stream
     */
    private function run(PlanExecutor $executor, array $command, Closure $announce, Closure $stream, bool $tty = false): bool
    {
        $announce(ShellCommand::display($command));

        return $executor->run($command, $stream, $tty);
    }
}
