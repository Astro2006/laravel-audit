<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\AboutDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

final class AboutStep implements Step
{
    public function step(): PreflightStep
    {
        return PreflightStep::About;
    }

    public function run(PreflightContext $context): StepResult
    {
        $result = $context->process()
            ->timeout(120)
            ->run($context->artisan(['about', '--json']));

        $about = Json::decode($result->output());

        $fallback = new AboutDTO((string) config('app.name'), app()->version(), PHP_VERSION, (string) app()->environment());

        if ($about === []) {
            return StepResult::error(
                $this->step(),
                'php artisan about --json produced no JSON: '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 5),
                $fallback,
            );
        }

        $context->writeJson('about.json', $about);

        return StepResult::ok($this->step(), new AboutDTO(
            name: Read::nullableString($about, 'environment.application_name') ?? $fallback->name,
            laravel: Read::nullableString($about, 'environment.laravel_version') ?? $fallback->laravel,
            php: Read::nullableString($about, 'environment.php_version') ?? $fallback->php,
            env: Read::nullableString($about, 'environment.environment') ?? $fallback->env,
            debug: data_get($about, 'environment.debug_mode') === null ? null : Read::bool($about, 'environment.debug_mode'),
        ));
    }
}
