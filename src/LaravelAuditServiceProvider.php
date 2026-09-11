<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit;

use Astro2006\LaravelAudit\Commands\AuditCommand;
use Astro2006\LaravelAudit\Commands\EvalCommand;
use Astro2006\LaravelAudit\Commands\InstallCommand;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelAuditServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-audit')
            ->hasConfigFile('audit')
            ->hasTranslations()
            ->hasCommands([
                AuditCommand::class,
                InstallCommand::class,
                EvalCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(HarnessFactory::class, fn () => new HarnessFactory((array) config('audit', [])));
    }

    public function packageBooted(): void
    {
        // Prompts can be published for tuning; published copies win.
        $this->publishes([
            $this->package->basePath('/../resources/prompts') => resource_path('prompts/vendor/laravel-audit'),
        ], 'audit-prompts');
    }
}
