<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Install\ComposerPreview;
use Astro2006\LaravelAudit\Install\InstallPlan;
use Astro2006\LaravelAudit\Install\InstallPlanner;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Install\PlannedPackage;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class InstallCommand extends Command
{
    protected $signature = 'audit:install
        {--dry-run : Only show what is missing and what would run}
        {--yes : Install everything in the plan without asking (except upgrades)}';

    protected $description = 'Set up laravel-audit: collect missing packages, ask for permission, install them';

    private Messages $messages;

    /** @var list<string> setup commands the user has to run */
    private array $manualSteps = [];

    public function handle(HarnessFactory $factory): int
    {
        $this->messages = Messages::fromConfig();
        $project = $factory->project();
        $config = $factory->allConfig();

        $this->newLine();
        $this->line(' <options=bold>'.$this->messages->get('install.header').'</>');

        if (! is_file(config_path('audit.php'))) {
            $this->callSilently('vendor:publish', ['--tag' => 'audit-config']);
        }

        $plan = (new InstallPlanner($project, $config, $factory->databaseGuard($project), (string) $factory->config('agent.binary', 'claude')))->plan();
        $executor = new PlanExecutor($project);
        $stubLevel = (int) $factory->config('preflight.phpstan.stub_level', 5);

        $this->presentPlan($plan, $executor, $stubLevel);

        if ($plan->isEmpty()) {
            $this->line('  <fg=green>✔</> '.$this->messages->get('install.nothing_missing'));

            return $this->finish($project, $config, $plan, [], [], false, $stubLevel);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line(' '.$this->messages->get('install.dry_run'));

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive() && ! $this->option('yes')) {
            $this->newLine();
            $this->line(' <fg=yellow>'.$this->messages->get('install.not_interactive').'</>');

            return $plan->packages === [] ? self::SUCCESS : self::FAILURE;
        }

        $selected = $this->consent($plan);
        $declined = array_values(array_diff($plan->names(), $selected->names()));

        if ($selected->composer() !== [] && ! $this->previewAccepted($selected, $executor)) {
            return self::FAILURE;
        }

        $failed = $this->runPlan($selected, $executor, $stubLevel);

        return $this->finish($project, $config, $selected, $declined, $failed, in_array('phpstan-stub', $selected->setup, true), $stubLevel);
    }

    private function presentPlan(InstallPlan $plan, PlanExecutor $executor, int $stubLevel): void
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

    /** @return list<string> */
    private function commandLines(InstallPlan $plan, PlanExecutor $executor, int $stubLevel): array
    {
        $lines = [];

        foreach ([$executor->composerCommand($plan), $executor->npmCommand($plan)] as $command) {
            if ($command !== null) {
                $lines[] = $this->display(array_values(array_filter($command, fn (string $part): bool => $part !== '--no-interaction')));
            }
        }

        foreach ($plan->setup as $step) {
            $lines[] = match ($step) {
                'phpstan-stub' => $this->messages->get('install.stub_planned', ['level' => $stubLevel]),
                'boost' => 'php artisan boost:install    ('.$this->messages->get('install.interactive').')',
                default => $this->display((array) $executor->setupCommand($step)),
            };
        }

        return $lines;
    }

    private function consent(InstallPlan $plan): InstallPlan
    {
        $regular = array_values(array_filter($plan->packages, fn (PlannedPackage $package): bool => ! $package->isUpgrade()));
        $upgrades = array_values(array_filter($plan->packages, fn (PlannedPackage $package): bool => $package->isUpgrade()));
        $names = array_map(fn (PlannedPackage $package): string => $package->name, $regular);

        if ($this->option('yes')) {
            return $plan->only($names);
        }

        if ($plan->packages !== []) {
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
        }

        return $plan->only($names);
    }

    private function previewAccepted(InstallPlan $plan, PlanExecutor $executor): bool
    {
        $command = $executor->composerCommand($plan, dryRun: true);

        if ($command === null) {
            return true;
        }

        $effects = (new ComposerPreview)->sideEffects($executor->capture($command), array_map(fn (PlannedPackage $package): string => $package->name, $plan->composer()));

        if ($effects === []) {
            return true;
        }

        $this->newLine();
        $this->line(' '.$this->messages->get('install.side_effects'));

        foreach ($effects as $effect) {
            $this->line('  '.$effect);
        }

        return $this->option('yes') || confirm($this->messages->get('install.side_effects_question'), default: true);
    }

    /** @return list<string> failed commands */
    private function runPlan(InstallPlan $plan, PlanExecutor $executor, int $stubLevel): array
    {
        $failed = [];
        $stream = fn (string $buffer) => $this->output->write($buffer);

        foreach ([$executor->composerCommand($plan), $executor->npmCommand($plan)] as $command) {
            if ($command !== null) {
                $this->newLine();
                $this->line(' <fg=gray>$ '.$this->display($command).'</>');

                if (! $executor->run($command, $stream)) {
                    $failed[] = $this->display($command);
                }
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

            if ($step === 'boost' && ! ($this->input->isInteractive() && SymfonyProcess::isTtySupported())) {
                $this->manualSteps[] = $this->display($command);

                continue;
            }

            $this->newLine();
            $this->line(' <fg=gray>$ '.$this->display($command).'</>');

            if (! $executor->run($command, $stream, tty: $step === 'boost')) {
                $failed[] = $this->display($command);
            }
        }

        return $failed;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $declined
     * @param  list<string>  $failed
     */
    private function finish(ProjectInspector $project, array $config, InstallPlan $plan, array $declined, array $failed, bool $stub, int $stubLevel): int
    {
        $outputPath = Read::string($config, 'output_path', storage_path('audit'));
        AuditRun::ensureOutputRoot($outputPath);

        // Re-check from the files: composer.lock and node_modules tell the
        // truth, independent of what this process has loaded.
        $fresh = new ProjectInspector($project->basePath);
        $installed = array_filter($plan->packages, fn (PlannedPackage $package): bool => $package->manager === 'npm'
            ? $fresh->hasNodeModule($package->name)
            : $fresh->isInstalled($package->name));

        $done = [];

        if ($installed !== []) {
            $done[] = $this->messages->get('install.installed', ['count' => count($installed)]);
        }

        if ($stub && $fresh->phpstanConfigPath() !== null) {
            $done[] = $this->messages->get('install.stub_created', ['level' => $stubLevel]);
        }

        $done[] = $this->messages->get('install.gitignore', ['path' => $project->relative($outputPath)]);

        $this->newLine();
        $this->line(' '.$this->messages->get('install.done'));
        $this->line('  <fg=green>✔</> '.implode(', ', $done));

        foreach ($declined as $package) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get('install.declined', ['package' => $package, 'consequence' => $this->messages->get('install.consequences.'.$package)]));
        }

        foreach ($failed as $command) {
            $this->line('  <fg=red>✘</> '.$this->messages->get('install.failed', ['command' => $command]));
        }

        foreach ($this->manualSteps as $command) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get('install.manual', ['command' => $command]));
        }

        foreach ($plan->manual as $key) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get($key === 'node' ? 'install.node_hint' : ($key === 'requirements.coverage_missing' ? 'install.coverage_hint' : $key), [
                'binary' => Read::string($config, 'agent.binary', 'claude'),
            ]));
        }

        $this->newLine();

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $command */
    private function display(array $command): string
    {
        $parts = array_map(fn (string $part): string => $part === PHP_BINARY ? 'php' : $part, $command);

        return implode(' ', array_map(fn (string $part): string => preg_match('/^[\w@:.\/=+-]+$/', $part) === 1 ? $part : escapeshellarg($part), $parts));
    }
}
