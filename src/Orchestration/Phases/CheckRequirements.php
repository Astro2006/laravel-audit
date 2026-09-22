<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Agents\Agent;
use Astro2006\LaravelAudit\Orchestration\RunPlan;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Messages;

/**
 * Checks tools, agent and environment before anything runs, and warns
 * about published prompts older than the package's own.
 */
final class CheckRequirements
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @return list<Check> */
    public function handle(RunPlan $plan, Messages $messages, bool $withAgents): array
    {
        $checks = (new RequirementChecker($plan->project, $this->config, $plan->guard, $plan->driver, $messages))
            ->check($plan->selection, $withAgents, app()->environment('production'));
        $outdated = Agent::outdatedOverrides(Agent::publishedPromptsPath());

        if ($outdated !== [] && $withAgents) {
            $checks[] = Check::warning($messages->get('requirements.prompts_outdated', ['files' => implode(', ', $outdated)]));
        }

        return $checks;
    }
}
