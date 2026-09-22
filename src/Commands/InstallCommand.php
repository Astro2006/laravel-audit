<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Console\InstallConsole;
use Astro2006\LaravelAudit\Services\InstallService;
use Astro2006\LaravelAudit\Support\Messages;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'audit:install
        {--dry-run : Only show what is missing and what would run}
        {--yes : Install everything in the plan without asking (except upgrades)}';

    protected $description = 'Set up laravel-audit: collect missing packages, ask for permission, install them';

    public function handle(InstallService $service): int
    {
        if (! is_file(config_path('audit.php'))) {
            $this->callSilently('vendor:publish', ['--tag' => 'audit-config']);
        }

        return $service->run(
            (bool) $this->option('dry-run'),
            (bool) $this->option('yes'),
            new InstallConsole($this->output, Messages::fromConfig(), $this->input->isInteractive()),
        );
    }
}
