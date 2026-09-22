<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Audit;

use Astro2006\LaravelAudit\Agents\Agent;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Checks tools, agent and environment before anything runs, and warns
 * about published prompts older than the package's own.
 */
final class CheckRequirements
{
    public function __construct(private readonly HarnessFactory $factory) {}

    /** @return list<Check> */
    public function handle(ProjectInspector $project, RunSelection $selection, DatabaseGuard $guard, AgentDriver $driver, Messages $messages, bool $withAgents): array
    {
        $checks = (new RequirementChecker($project, $this->factory->config, $guard, $driver, $messages))
            ->check($selection, $withAgents, app()->environment('production'));
        $outdated = Agent::outdatedOverrides($this->factory->promptOverridePath());

        if ($outdated !== [] && $withAgents) {
            $checks[] = Check::warning($messages->get('requirements.prompts_outdated', ['files' => implode(', ', $outdated)]));
        }

        return $checks;
    }
}
