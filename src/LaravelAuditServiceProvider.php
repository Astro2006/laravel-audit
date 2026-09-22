<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit;

use Astro2006\LaravelAudit\Audits\AuditDefinition;
use Astro2006\LaravelAudit\Commands\AuditCommand;
use Astro2006\LaravelAudit\Commands\EvalCommand;
use Astro2006\LaravelAudit\Commands\InstallCommand;
use Astro2006\LaravelAudit\Commands\ToolCommand;
use Astro2006\LaravelAudit\Contracts\Orchestrator;
use Astro2006\LaravelAudit\Orchestration\AuditOrchestrator;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Support\AuditConfig;
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
                ToolCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(AuditConfig::class, fn () => AuditConfig::load((array) config('audit', [])));
        $this->app->bind(Orchestrator::class, AuditOrchestrator::class);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            $this->package->basePath('/../resources/prompts') => resource_path(PromptTemplates::PUBLISHED_PATH),
        ], 'audit-prompts');

        $this->publishes([
            $this->package->basePath('/../resources/audits') => resource_path(AuditDefinition::PUBLISHED_PATH),
        ], 'audit-checks');
    }
}
