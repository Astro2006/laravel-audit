<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit;

use Astro2006\LaravelAudit\Console\Commands\AuditCommand;
use Astro2006\LaravelAudit\Console\Commands\EvalCommand;
use Astro2006\LaravelAudit\Console\Commands\InstallCommand;
use Astro2006\LaravelAudit\Console\Commands\ToolCommand;
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
    }

    public function packageBooted(): void
    {
        $this->publishes($this->packageFiles('*.md'), 'audit-prompts');
        $this->publishes($this->packageFiles('audit.php'), 'audit-checks');
    }

    /** @return array<string, string> package file => published file, for the files below resources/audits that match $pattern */
    private function packageFiles(string $pattern): array
    {
        $files = [];
        $root = PromptTemplates::packagePath();

        foreach ([...glob($root.'/*/'.$pattern) ?: [], ...glob($root.'/*/tasks/'.$pattern) ?: []] as $file) {
            $files[$file] = resource_path(PromptTemplates::PUBLISHED_PATH.'/'.substr($file, strlen($root) + 1));
        }

        return $files;
    }
}
