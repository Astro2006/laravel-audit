<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Audits\AuditDefinition;
use Astro2006\LaravelAudit\Orchestration\RunPlan;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Messages;

final class CheckRequirements
{
    public function __construct(private readonly AuditConfig $config) {}

    /** @return list<Check> */
    public function handle(RunPlan $plan, Messages $messages, bool $withAgents): array
    {
        $checks = (new RequirementChecker($plan->project, $this->config, $plan->guard, $plan->driver, $messages))
            ->check($plan->selection, $withAgents, app()->environment('production'));
        $outdated = PromptTemplates::outdated(PromptTemplates::publishedPath());

        if ($outdated !== [] && $withAgents) {
            $checks[] = Check::warning($messages->get('requirements.prompts_outdated', ['files' => implode(', ', $outdated)]));
        }

        foreach ($withAgents ? AuditDefinition::outdatedPublished() : [] as $audit => $missing) {
            $checks[] = Check::warning($messages->get('requirements.checks_outdated', ['audit' => $audit, 'checks' => implode(', ', $missing)]));
        }

        return $checks;
    }
}
