<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use RuntimeException;

/**
 * Renders the prompt of one task: _task.md (shared rules and output format)
 * plus the task's own instructions, or _summary.md for the summary task.
 * Placeholders are replaced with strtr, not Blade, because prompts contain
 * code examples.
 */
final class PromptRenderer
{
    /** Prompt files of the single-agent layout; a published copy is ignored now. */
    public const array OUTDATED_TEMPLATES = ['_base.md', 'tests.md', 'frontend.md', 'backend.md', 'dependencies.md', 'summary.md'];

    private const string VERIFY_TEMPLATE = 'tasks/_verify.md';

    /**
     * @param  string  $packagePath  resources/prompts of this package
     * @param  string|null  $overridePath  published prompts; files there win
     */
    public function __construct(
        private readonly string $packagePath,
        private readonly ?string $overridePath = null,
    ) {}

    /**
     * @param  array<string, string>  $dependencyContext  section title => content, from the tasks this one depends on
     * @param  list<string>  $missingDependencies  keys of dependencies that did not complete
     */
    public function renderTask(Audit $audit, AuditTask $task, PromptContext $context, array $dependencyContext = [], array $missingDependencies = []): RenderedPrompt
    {
        $text = strtr($this->template('_task.md')."\n\n".$this->template($task->promptFile()), [
            ...$this->placeholders($audit, $task, $context),
            '{{dependency_context}}' => $this->dependencyContext($dependencyContext, $missingDependencies),
            '{{sections_template}}' => implode('', array_map(fn (string $title): string => "## {$title}\n<see your task instructions>\n\n", $task->sections)),
        ]);

        return new RenderedPrompt($text, $this->taskHash($audit, $task));
    }

    /**
     * @param  string  $findings  the merged findings as a compact list
     * @param  list<string>  $failedTasks
     */
    public function renderSummary(Audit $audit, AuditTask $task, PromptContext $context, string $findings, array $failedTasks): RenderedPrompt
    {
        $text = strtr($this->template($task->promptFile()).$this->extra($task), [
            ...$this->placeholders($audit, $task, $context),
            '{{findings_list}}' => $findings === '' ? 'No findings.' : $findings,
            '{{failed_tasks}}' => $failedTasks === [] ? 'None.' : implode(', ', array_map(fn (string $key): string => '`'.$key.'`', $failedTasks)),
            '{{sections_template}}' => implode('', array_map(fn (string $title): string => "\n## {$title}\n<see the instructions above>\n", $task->sections)),
        ]);

        return new RenderedPrompt($text, $this->taskHash($audit, $task));
    }

    /**
     * The prompt of the verify job that checks the findings of one task.
     *
     * @param  string  $findings  the findings to verify, as the task wrote them
     */
    public function renderVerify(Audit $audit, AuditTask $task, PromptContext $context, string $findings): RenderedPrompt
    {
        $text = strtr($this->template(self::VERIFY_TEMPLATE), [
            ...$this->placeholders($audit, $task, $context),
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
    public function taskHash(Audit $audit, AuditTask $task): string
    {
        $templates = $task->isSummary()
            ? $this->template($task->promptFile())."\0".$this->extra($task)
            : $this->template('_task.md')."\0".$this->template($task->promptFile());

        return substr(sha1($templates."\0".json_encode([$task->checks, $task->sections, $task->dependsOn, array_intersect_key($audit->checks(), array_flip($task->checks))])), 0, 8);
    }

    /** @param list<string> $taskHashes */
    public static function auditHash(array $taskHashes): string
    {
        sort($taskHashes);

        return substr(sha1(implode("\0", $taskHashes)), 0, 8);
    }

    /**
     * Published copies of prompts the task layout no longer reads.
     *
     * @return list<string>
     */
    public function outdatedOverrides(): array
    {
        if ($this->overridePath === null) {
            return [];
        }

        return array_values(array_filter(self::OUTDATED_TEMPLATES, fn (string $file): bool => is_file($this->overridePath.'/'.$file)));
    }

    public static function languageLabel(string $locale): string
    {
        return match (strtolower(substr($locale, 0, 2))) {
            'de' => 'German (de)',
            'en' => 'English (en)',
            default => $locale,
        };
    }

    /** @return array<string, string> */
    private function placeholders(Audit $audit, AuditTask $task, PromptContext $context): array
    {
        $customChecks = $context->customChecksFor($audit->type()->value);

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
            '{{audit_key}}' => $audit->type()->value,
            '{{id_prefix}}' => $audit->idPrefix(),
            '{{audit_title}}' => $audit->title(),
            '{{task_key}}' => $task->key(),
            '{{task_title}}' => $task->title,
            '{{report_language}}' => self::languageLabel($context->reportLanguage),
            '{{exclude_paths}}' => implode(', ', array_map(fn (string $path): string => '`'.$path.'`', $context->excludePaths)),
            '{{max_findings}}' => (string) $context->maxFindings,
            '{{max_files}}' => (string) $context->maxFiles,
            '{{checks}}' => $this->checkList($audit, $task),
            '{{custom_checks}}' => $customChecks === []
                ? 'None.'
                : implode("\n", array_map(fn (string $rule): string => '- '.$rule, $customChecks)),
            '{{tools_note}}' => $context->toolsNote !== '' ? $context->toolsNote : 'You can read and search files. There is no shell, no web access and no write access. `.env` files are blocked on purpose.',
            '{{boost_note}}' => $context->boostAvailable
                ? 'Laravel Boost MCP tools are available (application info, database schema, routes, config keys, docs search). Use them before making assumptions, and search the Laravel docs via Boost when you are unsure whether something is idiomatic for this Laravel version.'
                : 'Laravel Boost is not available in this run. Work from the files.',
        ];
    }

    private function checkList(Audit $audit, AuditTask $task): string
    {
        $checks = $audit->checks();
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
        foreach (array_filter([$this->overridePath, $this->packagePath]) as $directory) {
            if (is_file($directory.'/'.$file)) {
                return true;
            }
        }

        return false;
    }

    private function template(string $file): string
    {
        foreach (array_filter([$this->overridePath, $this->packagePath]) as $directory) {
            $path = $directory.'/'.$file;

            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        throw new RuntimeException("Prompt template [{$file}] not found.");
    }
}
