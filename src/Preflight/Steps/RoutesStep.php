<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Routes\RouteDefinition;
use Astro2006\LaravelAudit\Data\Steps\RoutesDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteFacts;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;

final class RoutesStep implements Step
{
    public function __construct(private readonly RouteFacts $facts = new RouteFacts) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Routes;
    }

    public function run(PreflightContext $context): StepResult
    {
        $result = $context->process()
            ->timeout(120)
            ->run($context->artisan(['route:list', '--json', '-v']));

        $json = Json::decode($result->output());

        if ($json === [] && ! $result->successful()) {
            return StepResult::error($this->step(), 'php artisan route:list failed: '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 5));
        }

        $routes = RouteDefinition::listFromJson($json);
        $context->writeJson('routes.json', array_map(fn (RouteDefinition $route): array => $route->toArray(), $routes));

        $facts = $this->facts->facts($routes);
        $context->writeJson('route-facts.json', $facts);

        return StepResult::ok($this->step(), new RoutesDTO(count($routes), $facts->flagged()));
    }
}
