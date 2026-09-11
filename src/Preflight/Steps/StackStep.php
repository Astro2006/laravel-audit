<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;

final class StackStep implements Step
{
    /** stack label => [composer packages, npm packages] */
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
        $npm = array_map('strtolower', array_keys([
            ...(array) ($package['dependencies'] ?? []),
            ...(array) ($package['devDependencies'] ?? []),
        ]));

        $frontend = [];

        foreach (self::FRONTEND as $label => [$composer, $node]) {
            foreach ($composer as $name) {
                if ($project->isInstalled($name)) {
                    $frontend[] = $label;

                    continue 2;
                }
            }

            if (array_intersect($node, $npm) !== []) {
                $frontend[] = $label;
            }
        }

        return StepResult::ok($this->step(), [
            'frontend' => $frontend,
            'test_framework' => match (true) {
                $project->usesPest() => 'pest',
                $project->isInstalled('phpunit/phpunit') => 'phpunit',
                default => 'none',
            },
            'pest_version' => $project->majorVersion('pestphp/pest'),
            'browser_plugin' => $project->hasBrowserPlugin(),
            'boost' => $project->boostMcpServer() !== null,
            'package_manager' => $project->packageManager()->value,
        ]);
    }
}
