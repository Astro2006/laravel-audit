<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Run\RunContext;
use Astro2006\LaravelAudit\Skills\SkillInventory;
use Astro2006\LaravelAudit\Skills\SkillSelection;
use Astro2006\LaravelAudit\Skills\SkillSelector;
use Astro2006\LaravelAudit\Support\AuditConfig;

final class SelectSkills
{
    public function __construct(private readonly AuditConfig $config) {}

    public function handle(RunContext $context): SkillSelection
    {
        $plan = $context->plan;
        $console = $context->console;
        $inventory = SkillInventory::scan($plan->project->basePath);

        [$selection, $warnings] = (new SkillSelector($this->config))->select(
            self::tasks($plan->selection->audits),
            $inventory,
            $plan->driver,
            $context->run,
            $plan->project,
            $context->options->reselectSkills,
            fn () => $console->line('  '.$console->messages->get('agents.selecting_skills', ['installed' => count($inventory->skills)])),
        );

        $selection->write($context->run->dir().'/'.SkillSelection::FILE);

        $console->line('  '.($inventory->isEmpty()
            ? $console->messages->get('agents.no_skills')
            : $console->messages->get('agents.skills', ['installed' => count($inventory->skills), 'tasks' => $selection->count(), 'source' => $selection->source])));

        foreach ($warnings as $warning) {
            $console->line('  <fg=yellow>⚠</> '.$warning);
        }

        return $selection;
    }

    /**
     * The audit tasks of the selected audits, without summary and custom checks.
     *
     * @param  list<AuditType>  $audits
     * @return list<AuditTask>
     */
    public static function tasks(array $audits): array
    {
        $tasks = [];

        foreach ($audits as $type) {
            foreach ($type->audit(PromptTemplates::publishedPath())->tasks() as $task) {
                if (! $task->isSummary()) {
                    $tasks[] = $task;
                }
            }
        }

        return $tasks;
    }
}
