<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteFacts;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Illuminate\Support\Facades\Process;

final class RoutesStep implements Step
{
    public function __construct(private readonly RouteFacts $facts = new RouteFacts) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Routes;
    }

    public function run(PreflightContext $context): StepResult
    {
        $result = Process::path($context->project->basePath)
            ->timeout(120)
            ->run($context->artisan(['route:list', '--json', '-v']));

        $routes = Json::decode($result->output());

        if ($routes === [] && ! $result->successful()) {
            return StepResult::error($this->step(), 'php artisan route:list failed: '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 5));
        }

        $routes = array_values(array_filter($routes, 'is_array'));
        $context->writeJson('routes.json', $routes);

        $facts = $this->facts->facts($routes);
        $context->writeJson('route-facts.json', $facts);

        return StepResult::ok($this->step(), [
            'count' => count($routes),
            'flagged' => $facts['flagged'],
            'file' => 'routes.json',
            'facts_file' => 'route-facts.json',
        ]);
    }
}
