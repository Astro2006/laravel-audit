<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

use Astro2006\LaravelAudit\Console\Output\InstallConsole;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;

final class BoostHandoff
{
    public const string PACKAGE = 'astro2006/laravel-audit';

    public function __construct(
        private readonly ProjectInspector $project,
        private readonly PlanExecutor $executor,
        private readonly string $phpBinary = PHP_BINARY,
    ) {}

    /**
     * Registers the package's guideline and skill with Boost: interactive
     * through Boost's own discovery prompt, with --yes by writing boost.json.
     *
     * @param  bool  $tty  whether Boost's prompt can run here
     */
    public function handle(InstallConsole $console, bool $yes, bool $dryRun, bool $tty): bool
    {
        if (! $this->project->isInstalled('laravel/boost')) {
            $console->note('install.boost_missing');

            return true;
        }

        $path = $this->project->path('boost.json');
        $config = Json::readFile($path);

        if (Read::strings($config, 'agents') === []) {
            $console->note('install.boost_not_set_up');

            return true;
        }

        $registered = in_array(self::PACKAGE, Read::strings($config, 'packages'), true);
        $discover = [$this->phpBinary, 'artisan', 'boost:update', '--discover'];
        $refresh = [$this->phpBinary, 'artisan', 'boost:update', '--no-discover'];

        if ($dryRun) {
            $console->line('  '.$console->messages->get('install.boost_would_run', ['command' => ShellCommand::display($registered ? $refresh : $discover)]));

            return true;
        }

        if ($console->interactive && $tty) {
            if (! $console->confirm('install.boost_question', ['command' => ShellCommand::display($registered ? $refresh : $discover)])) {
                return true;
            }

            return $this->run($console, $registered ? $refresh : $discover, tty: ! $registered);
        }

        if (! $yes) {
            $console->line('  '.$console->messages->get('install.manual', ['command' => ShellCommand::display($discover)]));

            return true;
        }

        if (! $registered) {
            Json::writeFile($path, [...$config, 'packages' => [...Read::strings($config, 'packages'), self::PACKAGE]]);
        }

        return $this->run($console, $refresh, tty: false);
    }

    /** @param  list<string>  $command */
    private function run(InstallConsole $console, array $command, bool $tty): bool
    {
        $console->command(ShellCommand::display($command));

        return $this->executor->run($command, $console->write(...), $tty);
    }
}
