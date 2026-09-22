<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Prompts\TaskPlaceholders;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * One audit domain, fully self-contained: what it checks, how it splits into
 * tasks, and how it generates its own prompts (_task.md plus the task's own
 * instructions below resources/prompts/agents, or _summary.md for the
 * summary task). Placeholders are replaced with strtr, not Blade, because
 * prompts contain code examples.
 */
abstract class Agent implements Audit
{
    private const string BASE_TEMPLATE = 'agents/_task.md';

    private const string VERIFY_TEMPLATE = 'agents/_verify.md';

    private readonly PromptTemplates $templates;

    /** @param  string|null  $overridePath  published prompts (PromptTemplates::publishedPath()); files there win */
    public function __construct(?string $overridePath = null)
    {
        $this->templates = new PromptTemplates($overridePath);
    }

    final public function tasks(bool $withCustom = false): array
    {
        $tasks = $this->taskList();

        if ($withCustom) {
            $tasks[] = $this->task(AuditTask::CUSTOM, 'Custom checks', ['custom']);
        }

        $tasks[] = $this->task(
            AuditTask::SUMMARY,
            'Summary',
            [],
            sections: $this->summarySections(),
            dependsOn: array_map(fn (AuditTask $task): string => $task->name, $tasks),
        );

        return $tasks;
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        return [];
    }

    public function skipReason(AuditTask $task, PreflightSummary $preflight): ?string
    {
        return null;
    }

    public function findingGuard(PreflightSummary $preflight, ProjectInspector $project): ?FindingGuard
    {
        return null;
    }

    /**
     * @param  array<string, string>  $dependencyContext  section title => content, from the tasks this one depends on
     * @param  list<string>  $missingDependencies  keys of dependencies that did not complete
     */
    public function renderTask(AuditTask $task, PromptContext $context, array $dependencyContext = [], array $missingDependencies = []): RenderedPrompt
    {
        $text = strtr($this->templates->get(self::BASE_TEMPLATE)."\n\n".$this->templates->get($task->promptFile()), [
            ...TaskPlaceholders::for($this, $task, $context),
            '{{snapshot}}' => TaskPlaceholders::snapshots($task, $context),
            '{{dependency_context}}' => TaskPlaceholders::dependencyContext($dependencyContext, $missingDependencies),
            '{{sections_template}}' => implode('', array_map(fn (string $title): string => "## {$title}\n<see your task instructions>\n\n", $task->sections)),
        ]);

        return new RenderedPrompt($text, $this->taskHash($task));
    }

    /**
     * @param  string  $findings  the merged findings as a compact list
     * @param  list<string>  $failedTasks
     */
    public function renderSummary(AuditTask $task, PromptContext $context, string $findings, array $failedTasks): RenderedPrompt
    {
        $text = strtr($this->templates->get($task->promptFile()).$this->extra($task), [
            ...TaskPlaceholders::for($this, $task, $context),
            '{{findings_list}}' => $findings === '' ? 'No findings.' : $findings,
            '{{failed_tasks}}' => $failedTasks === [] ? 'None.' : implode(', ', array_map(fn (string $key): string => '`'.$key.'`', $failedTasks)),
            '{{sections_template}}' => implode('', array_map(fn (string $title): string => "\n## {$title}\n<see the instructions above>\n", $task->sections)),
        ]);

        return new RenderedPrompt($text, $this->taskHash($task));
    }

    /**
     * The prompt of the verify job that checks the findings of one task.
     *
     * @param  string  $findings  the findings to verify, as the task wrote them
     */
    public function renderVerify(AuditTask $task, PromptContext $context, string $findings): RenderedPrompt
    {
        $text = strtr($this->templates->get(self::VERIFY_TEMPLATE), [
            ...TaskPlaceholders::for($this, $task, $context),
            '{{findings}}' => $findings,
        ]);

        return new RenderedPrompt($text, $this->verifyHash());
    }

    public function verifyHash(): string
    {
        return $this->templates->hash(self::VERIFY_TEMPLATE);
    }

    /**
     * Depends on the templates and the task definition only, never on the
     * run, so equal hashes mean equal instructions.
     */
    public function taskHash(AuditTask $task): string
    {
        $templates = $task->isSummary()
            ? $this->templates->get($task->promptFile())."\0".$this->extra($task)
            : $this->templates->get(self::BASE_TEMPLATE)."\0".$this->templates->get($task->promptFile());

        $snapshots = array_map(fn (SnapshotType $type): string => $type->value, TaskPlaceholders::snapshotTypes($task));

        return substr(sha1($templates."\0".json_encode([$task->checks, $task->sections, $task->dependsOn, $snapshots, array_intersect_key($this->checks(), array_flip($task->checks))])), 0, 8);
    }

    /** @param  list<string>  $taskHashes */
    public static function auditHash(array $taskHashes): string
    {
        sort($taskHashes);

        return substr(sha1(implode("\0", $taskHashes)), 0, 8);
    }

    /**
     * The audit's own tasks, without custom and summary.
     *
     * @return list<AuditTask>
     */
    abstract protected function taskList(): array;

    /**
     * Sections the summary task writes from the merged findings.
     *
     * @return list<string>
     */
    protected function summarySections(): array
    {
        return [];
    }

    /**
     * @param  list<string>  $checks
     * @param  list<string>  $sections
     * @param  list<string>  $dependsOn
     * @param  list<SnapshotType>  $snapshots  preflight data the task gets in its prompt
     */
    protected function task(string $name, string $title, array $checks, array $sections = [], array $dependsOn = [], array $snapshots = []): AuditTask
    {
        return new AuditTask($this->type(), $name, $title, $checks, $sections, $dependsOn, $snapshots);
    }

    /**
     * Checks every audit shares.
     *
     * @return array<string, string>
     */
    protected function commonChecks(): array
    {
        return [
            'custom' => 'A project-specific rule from the custom checks list.',
            'other' => 'Fits none of the checks above. Use sparingly.',
        ];
    }

    private function extra(AuditTask $task): string
    {
        $file = $task->extraPromptFile();

        return $file === null || ! $this->templates->has($file) ? '' : "\n\n".$this->templates->get($file);
    }
}
