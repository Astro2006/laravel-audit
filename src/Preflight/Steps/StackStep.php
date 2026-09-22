<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\StackDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Read;

final class StackStep implements Step
{
    /** stack label => composer packages and npm packages that reveal it */
    private const array FRONTEND = [
        'livewire' => [['livewire/livewire'], []],
        'inertia' => [['inertiajs/inertia-laravel'], ['@inertiajs/vue3', '@inertiajs/react', '@inertiajs/svelte']],
        'filament' => [['filament/filament'], []],
        'vue' => [[], ['vue']],
        'react' => [[], ['react']],
        'svelte' => [[], ['svelte']],
        'alpine' => [[], ['alpinejs']],
        'tailwind' => [[], ['tailwindcss', '@tailwindcss/vite']],
        'vite' => [[], ['vite']],
    ];

    public function step(): PreflightStep
    {
        return PreflightStep::Stack;
    }

    public function run(PreflightContext $context): StepResult
    {
        $project = $context->project;
        $package = $project->packageJson();
        $npm = array_map('strtolower', [...Read::keys($package, 'dependencies'), ...Read::keys($package, 'devDependencies')]);

        $frontend = [];

        foreach (self::FRONTEND as $label => [$composer, $node]) {
            $viaComposer = array_filter($composer, fn (string $name): bool => $project->isInstalled($name)) !== [];

            if ($viaComposer || array_intersect($node, $npm) !== []) {
                $frontend[] = $label;
            }
        }

        return StepResult::ok($this->step(), new StackDTO(
            frontend: $frontend,
            testFramework: match (true) {
                $project->usesPest() => 'pest',
                $project->isInstalled('phpunit/phpunit') => 'phpunit',
                default => 'none',
            },
            pestVersion: $project->majorVersion('pestphp/pest'),
            browserPlugin: $project->hasBrowserPlugin(),
            boost: $project->boostMcpServer() !== null,
            packageManager: $project->packageManager()->value,
        ));
    }
}
