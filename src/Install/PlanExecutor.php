<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

use Astro2006\LaravelAudit\Support\ProjectInspector;
use Closure;
use Illuminate\Support\Facades\Process;

/**
 * Turns an install plan into commands and runs them: one composer require
 * for all Composer packages, one install for all npm packages, then the
 * setup steps.
 */
final class PlanExecutor
{
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly string $phpBinary = PHP_BINARY,
    ) {}

    /** @return list<string>|null */
    public function composerCommand(InstallPlan $plan, bool $dryRun = false): ?array
    {
        $packages = $plan->composer();

        if ($packages === []) {
            return null;
        }

        $command = [...$this->composerBinary(), 'require', '--dev', ...array_map(fn (PlannedPackage $package): string => $package->installName(), $packages)];

        // Upgrades (e.g. PHPUnit → Pest) need Composer to move dependencies.
        foreach ($packages as $package) {
            if ($package->isUpgrade()) {
                $command[] = '--with-all-dependencies';

                break;
            }
        }

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $command[] = '--no-interaction';

        return $command;
    }

    /** @return list<string>|null */
    public function npmCommand(InstallPlan $plan): ?array
    {
        $packages = array_map(fn (PlannedPackage $package): string => $package->installName(), $plan->npm());

        return $packages === [] ? null : $this->project->packageManager()->installDevCommand($packages);
    }

    /** @return list<string>|null */
    public function setupCommand(string $step): ?array
    {
        return match ($step) {
            'boost' => [$this->phpBinary, 'artisan', 'boost:install'],
            'playwright-browsers' => [...$this->project->packageManager()->execCommand('playwright'), 'install'],
            default => null,
        };
    }

    /** Creates phpstan.neon from the stub, but never overwrites a config. */
    public function writeStub(int $level): bool
    {
        if ($this->project->phpstanConfigPath() !== null) {
            return false;
        }

        $stub = (string) file_get_contents(dirname(__DIR__, 2).'/resources/stubs/phpstan.neon.stub');
        file_put_contents($this->project->path('phpstan.neon'), str_replace('{{level}}', (string) $level, $stub));

        return true;
    }

    /**
     * @param  list<string>  $command
     * @param  Closure(string): void  $output
     */
    public function run(array $command, Closure $output, bool $tty = false): bool
    {
        $pending = Process::path($this->project->basePath)->forever();

        if ($tty) {
            return $pending->tty()->run($command)->successful();
        }

        return $pending->run($command, fn (string $type, string $buffer) => $output($buffer))->successful();
    }

    /** @param list<string> $command */
    public function capture(array $command): string
    {
        $result = Process::path($this->project->basePath)->timeout(600)->run($command);

        return $result->output()."\n".$result->errorOutput();
    }

    /** @return list<string> */
    private function composerBinary(): array
    {
        if ($this->project->findExecutable('composer') !== null) {
            return ['composer'];
        }

        if (is_file($this->project->path('composer.phar'))) {
            return [$this->phpBinary, 'composer.phar'];
        }

        return ['composer'];
    }
}
