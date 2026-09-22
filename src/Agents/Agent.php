<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use RuntimeException;

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

    /** Prompt files of the single-agent layout; a published copy is ignored now. */
    /** Where `vendor:publish --tag=audit-prompts` puts the prompts, below resources/. */
    public const string PUBLISHED_PROMPTS = 'prompts/vendor/laravel-audit';

    private const array OUTDATED_TEMPLATES = ['_base.md', 'tests.md', 'frontend.md', 'backend.md', 'dependencies.md', 'summary.md'];

    /** @param  string|null  $overridePath  published prompts (resource_path('prompts/vendor/laravel-audit')); files there win */
    public function __construct(private readonly ?string $overridePath = null) {}

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
        $text = strtr($this->template(self::BASE_TEMPLATE)."\n\n".$this->template($task->promptFile()), [
            ...$this->placeholders($task, $context),
            '{{snapshot}}' => $this->snapshot($task, $context),
            '{{dependency_context}}' => $this->dependencyContext($dependencyContext, $missingDependencies),
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
        $text = strtr($this->template($task->promptFile()).$this->extra($task), [
            ...$this->placeholders($task, $context),
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
        $text = strtr($this->template(self::VERIFY_TEMPLATE), [
            ...$this->placeholders($task, $context),
            '{{findings}}' => $findings,
        ]);

        return new RenderedPrompt($text, $this->verifyHash());
    }

    public function verifyHash(): string
    {
        return substr(sha1($this->template(self::VERIFY_TEMPLATE)), 0, 8);
    }

    /**
     * Depends on the templates and the task definition only, never on the
     * run, so equal hashes mean equal instructions.
     */
    public function taskHash(AuditTask $task): string
    {
        $templates = $task->isSummary()
            ? $this->template($task->promptFile())."\0".$this->extra($task)
            : $this->template(self::BASE_TEMPLATE)."\0".$this->template($task->promptFile());

        $snapshots = array_map(fn (SnapshotType $type): string => $type->value, $this->snapshotTypes($task));

        return substr(sha1($templates."\0".json_encode([$task->checks, $task->sections, $task->dependsOn, $snapshots, array_intersect_key($this->checks(), array_flip($task->checks))])), 0, 8);
    }

    /** @param  list<string>  $taskHashes */
    public static function auditHash(array $taskHashes): string
    {
        sort($taskHashes);

        return substr(sha1(implode("\0", $taskHashes)), 0, 8);
    }

    public static function languageLabel(string $locale): string
    {
        return match (strtolower(substr($locale, 0, 2))) {
            'de' => 'German (de)',
            'en' => 'English (en)',
            default => $locale,
        };
    }

    /** Published prompts; files there win over the package's own. */
    public static function publishedPromptsPath(): ?string
    {
        return function_exists('resource_path') ? resource_path(self::PUBLISHED_PROMPTS) : null;
    }

    /**
     * Published copies of prompts the task layout no longer reads.
     *
     * @return list<string>
     */
    public static function outdatedOverrides(?string $overridePath): array
    {
        if ($overridePath === null) {
            return [];
        }

        return array_values(array_filter(self::OUTDATED_TEMPLATES, fn (string $file): bool => is_file($overridePath.'/'.$file)));
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

    /** @return array<string, string> */
    private function placeholders(AuditTask $task, PromptContext $context): array
    {
        $customChecks = $context->customChecksFor($this->type()->value);

        return [
            '{{project_name}}' => $context->projectName,
            '{{laravel_version}}' => $context->laravelVersion,
            '{{php_version}}' => $context->phpVersion,
            '{{test_framework}}' => $context->testFramework,
            '{{frontend_stack}}' => $context->frontendStack,
            '{{phpstan_level}}' => $context->phpstanLevel,
            '{{browser_tests}}' => $context->browserTests,
            '{{run_id}}' => $context->runId,
            '{{output_dir}}' => $context->outputDir,
            '{{preflight_dir}}' => $context->preflightDir,
            '{{audit_key}}' => $this->type()->value,
            '{{id_prefix}}' => $this->idPrefix(),
            '{{audit_title}}' => $this->title(),
            '{{task_key}}' => $task->key(),
            '{{task_title}}' => $task->title,
            '{{report_language}}' => self::languageLabel($context->reportLanguage),
            '{{exclude_paths}}' => implode(', ', array_map(fn (string $path): string => '`'.$path.'`', $context->excludePaths)),
            '{{max_findings}}' => (string) $context->maxFindings,
            '{{max_files}}' => (string) $context->maxFiles,
            '{{checks}}' => $this->checkList($task),
            '{{custom_checks}}' => $customChecks === []
                ? 'None.'
                : implode("\n", array_map(fn (string $rule): string => '- '.$rule, $customChecks)),
            '{{tools_note}}' => $context->toolsNote !== '' ? $context->toolsNote : 'You can read and search files. There is no shell, no web access and no write access. `.env` files are blocked on purpose.',
            '{{boost_note}}' => $context->boostAvailable
                ? 'Laravel Boost MCP tools are available (application info, database schema, routes, config keys, docs search). Use them before making assumptions, and search the Laravel docs via Boost when you are unsure whether something is idiomatic for this Laravel version.'
                : 'Laravel Boost is not available in this run. Work from the files.',
        ];
    }

    /**
     * The status of the preflight steps first, then the task's own snapshots.
     *
     * @return list<SnapshotType>
     */
    private function snapshotTypes(AuditTask $task): array
    {
        return array_values(array_unique([SnapshotType::Status, ...$task->snapshots], SORT_REGULAR));
    }

    private function snapshot(AuditTask $task, PromptContext $context): string
    {
        $parts = [];

        foreach ($this->snapshotTypes($task) as $type) {
            $parts[] = $context->snapshots[$type->value] ?? "### {$type->label()}\nNo data in this run (see the preflight steps).";
        }

        return implode("\n\n", $parts);
    }

    private function checkList(AuditTask $task): string
    {
        $checks = $this->checks();
        $lines = [];

        foreach ([...$task->checks, 'other'] as $slug) {
            $lines[] = sprintf('- `%s` — %s', $slug, $checks[$slug] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $sections
     * @param  list<string>  $missing
     */
    private function dependencyContext(array $sections, array $missing): string
    {
        $parts = [];

        foreach ($sections as $title => $content) {
            $parts[] = "### {$title}\n\n".trim($content);
        }

        foreach ($missing as $key) {
            $parts[] = "The task `{$key}` did not complete, so its results are missing. Establish what you need yourself from a few representative files.";
        }

        return $parts === [] ? '' : "## Context from other tasks\n\nOther tasks of this audit already produced this. Rely on it instead of redoing the work.\n\n".implode("\n\n", $parts);
    }

    private function extra(AuditTask $task): string
    {
        $file = $task->extraPromptFile();

        return $file === null || ! $this->exists($file) ? '' : "\n\n".$this->template($file);
    }

    private function exists(string $file): bool
    {
        foreach (array_filter([$this->overridePath, $this->packagePath()]) as $directory) {
            if (is_file($directory.'/'.$file)) {
                return true;
            }
        }

        return false;
    }

    private function template(string $file): string
    {
        foreach (array_filter([$this->overridePath, $this->packagePath()]) as $directory) {
            $path = $directory.'/'.$file;

            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        throw new RuntimeException("Prompt template [{$file}] not found.");
    }

    /** resources/prompts of this package. */
    private function packagePath(): string
    {
        return dirname(__DIR__, 2).'/resources/prompts';
    }
}
