<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\InstallResult;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Install\PlannedPackage;
use Astro2006\LaravelAudit\Install\ShellCommand;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Console\OutputStyle;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Everything `php artisan audit:install` prints and asks.
 */
final class InstallConsole
{
    public function __construct(
        private readonly OutputStyle $output,
        public readonly Messages $messages,
        public readonly bool $interactive,
    ) {}

    public function line(string $line): void
    {
        $this->output->writeln($line);
    }

    public function newLine(): void
    {
        $this->output->newLine();
    }

    public function write(string $buffer): void
    {
        $this->output->write($buffer);
    }

    public function header(): void
    {
        $this->newLine();
        $this->line(' <options=bold>'.$this->messages->get('install.header').'</>');
    }

    /** A short note on its own, like "dry run" or "not interactive". */
    public function note(string $key, string $color = ''): void
    {
        $this->newLine();
        $this->line(' '.($color === '' ? $this->messages->get($key) : '<fg='.$color.'>'.$this->messages->get($key).'</>'));
    }

    public function command(string $command): void
    {
        $this->newLine();
        $this->line(' <fg=gray>$ '.$command.'</>');
    }

    public function plan(InstallPlan $plan, PlanExecutor $executor, int $stubLevel): void
    {
        if ($plan->present !== []) {
            $this->newLine();
            $this->line(' '.$this->messages->get('install.present'));

            foreach ($plan->present as $package) {
                $this->line('  <fg=green>✔</> '.$package);
            }
        }

        if ($plan->packages !== []) {
            $this->newLine();
            $this->line(' '.$this->messages->get('install.need'));
            $this->newLine();

            $width = max(array_map(fn (PlannedPackage $package): int => mb_strlen($package->name), $plan->packages)) + 2;

            foreach ($plan->packages as $package) {
                $manager = $package->manager === 'npm' ? 'npm --save-dev' : 'composer --dev';
                $tag = $package->isUpgrade() ? '  <fg=yellow>['.$this->messages->get('install.upgrade_tag').': '.$package->upgradeReason.']</>' : '';

                $this->line(sprintf(
                    '  %s%s  <fg=gray>%s</>%s',
                    str_pad($package->name, $width),
                    $this->messages->get('install.purposes.'.$package->name),
                    $manager,
                    $tag,
                ));
            }
        }

        foreach ($plan->notes as $note) {
            $this->line('  <fg=blue>ℹ</> '.$this->messages->get($note->key, $note->replace));
        }

        $commands = $this->commandLines($plan, $executor, $stubLevel);

        if ($commands !== []) {
            $this->newLine();
            $this->line(' '.$this->messages->get('install.will_run'));

            foreach ($commands as $command) {
                $this->line('  '.$command);
            }
        }
    }

    /**
     * Asks which packages to install; upgrades are always asked one by one.
     *
     * @return list<string> package names
     */
    public function choosePackages(InstallPlan $plan): array
    {
        $regular = array_values(array_filter($plan->packages, fn (PlannedPackage $package): bool => ! $package->isUpgrade()));
        $upgrades = array_values(array_filter($plan->packages, fn (PlannedPackage $package): bool => $package->isUpgrade()));
        $names = array_map(fn (PlannedPackage $package): string => $package->name, $regular);

        if ($plan->packages === []) {
            return $names;
        }

        $choice = select(
            label: $this->messages->get('install.question'),
            options: [
                'all' => $this->messages->get('install.choice_all'),
                'select' => $this->messages->get('install.choice_select'),
                'none' => $this->messages->get('install.choice_none'),
            ],
        );

        $names = match ($choice) {
            'all' => $names,
            'select' => array_values(array_map('strval', multiselect(
                label: $this->messages->get('install.select_label'),
                options: array_combine($names, $names) ?: [],
                default: $names,
            ))),
            default => [],
        };

        if ($choice !== 'none') {
            foreach ($upgrades as $upgrade) {
                if (confirm($this->messages->get('install.upgrade_question', ['package' => $upgrade->name, 'reason' => (string) $upgrade->upgradeReason]), default: false)) {
                    $names[] = $upgrade->name;
                }
            }
        }

        return $names;
    }

    /** @param list<string> $effects */
    public function sideEffects(array $effects): void
    {
        $this->newLine();
        $this->line(' '.$this->messages->get('install.side_effects'));

        foreach ($effects as $effect) {
            $this->line('  '.$effect);
        }
    }

    public function confirmSideEffects(): bool
    {
        return confirm($this->messages->get('install.side_effects_question'), default: true);
    }

    public function result(InstallResult $result, ProjectInspector $project, ?AgentDriver $driver): void
    {
        $done = [];

        if ($result->installed > 0) {
            $done[] = $this->messages->get('install.installed', ['count' => $result->installed]);
        }

        if ($result->stubCreated) {
            $done[] = $this->messages->get('install.stub_created', ['level' => $result->stubLevel]);
        }

        $done[] = $this->messages->get('install.gitignore', ['path' => $project->relative($result->outputPath)]);

        $this->newLine();
        $this->line(' '.$this->messages->get('install.done'));
        $this->line('  <fg=green>✔</> '.implode(', ', $done));

        foreach ($result->declined as $package) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get('install.declined', ['package' => $package, 'consequence' => $this->messages->get('install.consequences.'.$package)]));
        }

        foreach ($result->failed as $command) {
            $this->line('  <fg=red>✘</> '.$this->messages->get('install.failed', ['command' => $command]));
        }

        foreach ($result->manualCommands as $command) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get('install.manual', ['command' => $command]));
        }

        foreach ($result->manualKeys as $key) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get(match ($key) {
                'node' => 'install.node_hint',
                'requirements.coverage_missing' => 'install.coverage_hint',
                default => $key,
            }, [
                'agent' => $driver?->displayName() ?? 'Agent',
                'binary' => $driver?->binary() ?? '',
            ]));
        }

        $this->newLine();
    }

    /** @return list<string> */
    private function commandLines(InstallPlan $plan, PlanExecutor $executor, int $stubLevel): array
    {
        $lines = [];

        foreach ([$executor->composerCommand($plan), $executor->npmCommand($plan)] as $command) {
            if ($command !== null) {
                $lines[] = ShellCommand::display(array_values(array_filter($command, fn (string $part): bool => $part !== '--no-interaction')));
            }
        }

        foreach ($plan->setup as $step) {
            $lines[] = match ($step) {
                'phpstan-stub' => $this->messages->get('install.stub_planned', ['level' => $stubLevel]),
                'boost' => 'php artisan boost:install    ('.$this->messages->get('install.interactive').')',
                default => ShellCommand::display((array) $executor->setupCommand($step)),
            };
        }

        return $lines;
    }
}
