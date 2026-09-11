<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Illuminate\Support\Facades\Process;

final class AboutStep implements Step
{
    public function step(): PreflightStep
    {
        return PreflightStep::About;
    }

    public function run(PreflightContext $context): StepResult
    {
        $result = Process::path($context->project->basePath)
            ->timeout(120)
            ->run($context->artisan(['about', '--json']));

        $about = Json::decode($result->output());

        // The running app knows the basics even if "about" fails.
        $fallback = [
            'name' => (string) config('app.name'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'env' => (string) app()->environment(),
        ];

        if ($about === []) {
            return StepResult::error(
                $this->step(),
                'php artisan about --json produced no JSON: '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 5),
                $fallback,
            );
        }

        $context->writeJson('about.json', $about);
        $environment = (array) ($about['environment'] ?? []);

        return StepResult::ok($this->step(), [
            'name' => $environment['application_name'] ?? $fallback['name'],
            'laravel' => $environment['laravel_version'] ?? $fallback['laravel'],
            'php' => $environment['php_version'] ?? $fallback['php'],
            'env' => $environment['environment'] ?? $fallback['env'],
            'debug' => $environment['debug_mode'] ?? null,
            'file' => 'about.json',
        ]);
    }
}
