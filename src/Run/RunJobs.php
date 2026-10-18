<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\Snapshots\SnapshotWriter;
use Astro2006\LaravelAudit\Prompts\PromptContextBuilder;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Skills\SkillInventory;
use Astro2006\LaravelAudit\Skills\SkillSection;
use Astro2006\LaravelAudit\Skills\SkillSelection;
use Astro2006\LaravelAudit\Standards\GuidelineSource;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\McpServer;

final class RunJobs
{
    public function __construct(private readonly AuditConfig $config) {}

    /**
     * @param  list<string>|null  $only  task keys to plan; null = every task
     * @param  bool  $withHarnessJobs  plan the summary tasks and the results job too
     */
    public function plan(RunPlan $plan, AuditRun $run, PreflightSummary $summary, string $locale, ?array $only = null, bool $withHarnessJobs = true): JobPlan
    {
        $project = $plan->project;
        $driver = $plan->driver;

        $extra = str_starts_with($run->dir(), rtrim($project->basePath, '/').'/') ? [] : [$run->outputRoot];
        $driverConfig = $driver->writeConfig($run->logsDir(), $extra);
        $snapshots = SnapshotWriter::standard($this->config->int('snapshot_rows'))->write($summary, $project->relative($run->preflightDir()));
        $skills = SkillSection::render(SkillSelection::read($run->dir().'/'.SkillSelection::FILE) ?? new SkillSelection, SkillInventory::scan($project->basePath), max(200, $this->config->int('skill_max_chars')));
        $standards = GuidelineSource::for($project->basePath, $this->config)->render(max(200, $this->config->int('standards_max_chars')));
        $prompt = (new PromptContextBuilder)->build($summary, $run, $project, $this->config, $locale, McpServer::forProject($project) !== null, $driver->toolsNote(), $snapshots, $skills, $standards);
        $overridePath = PromptTemplates::publishedPath();
        $requests = new TaskRequests($prompt, $overridePath, $run, $project->basePath, $driverConfig, $extra);

        return (new JobPlanner($requests, TaskSettings::fromConfig($this->config), $overridePath))
            ->plan($plan->selection->audits, $summary, $prompt, $project, $only, $withHarnessJobs);
    }
}
