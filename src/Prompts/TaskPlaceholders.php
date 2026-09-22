<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Support\Language;
use Illuminate\Support\Arr;

final class TaskPlaceholders
{
    private const string TOOLS_NOTE = 'You can read and search files. There is no shell, no web access and no write access. `.env` files are blocked on purpose.';

    private const string BOOST_NOTE = 'Laravel Boost MCP tools are available (application info, database schema, routes, config keys, docs search). Use them before making assumptions, and search the Laravel docs via Boost when you are unsure whether something is idiomatic for this Laravel version.';

    private const string NO_BOOST_NOTE = 'Laravel Boost is not available in this run. Work from the files.';

    /** @return array<string, string> */
    public static function for(Audit $audit, AuditTask $task, PromptContext $context): array
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
            '{{report_language}}' => Language::label($context->reportLanguage),
            '{{exclude_paths}}' => implode(', ', array_map(fn (string $path): string => '`'.$path.'`', $context->excludePaths)),
            '{{max_findings}}' => (string) $context->maxFindings,
            '{{max_files}}' => (string) $context->maxFiles,
            '{{checks}}' => self::checkList($audit, $task),
            '{{custom_checks}}' => $customChecks === []
                ? 'None.'
                : implode("\n", array_map(fn (string $rule): string => '- '.$rule, $customChecks)),
            '{{tools_note}}' => $context->toolsNote !== '' ? $context->toolsNote : self::TOOLS_NOTE,
            '{{boost_note}}' => $context->boostAvailable ? self::BOOST_NOTE : self::NO_BOOST_NOTE,
        ];
    }

    /**
     * The status of the preflight steps first, then the task's own snapshots.
     *
     * @return list<SnapshotType>
     */
    public static function snapshotTypes(AuditTask $task): array
    {
        return array_values(array_unique([SnapshotType::Status, ...$task->snapshots], SORT_REGULAR));
    }

    public static function snapshots(AuditTask $task, PromptContext $context): string
    {
        $parts = [];

        foreach (self::snapshotTypes($task) as $type) {
            $parts[] = Arr::get($context->snapshots, $type->value) ?? "### {$type->label()}\nNo data in this run (see the preflight steps).";
        }

        return implode("\n\n", $parts);
    }

    /**
     * What tasks this one depends on already produced, and which of them failed.
     *
     * @param  array<string, string>  $sections  section title => content
     * @param  list<string>  $missing  keys of dependencies that did not complete
     */
    public static function dependencyContext(array $sections, array $missing): string
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

    private static function checkList(Audit $audit, AuditTask $task): string
    {
        $checks = $audit->checks();
        $lines = [];

        foreach ([...$task->checks, 'other'] as $slug) {
            $lines[] = sprintf('- `%s` — %s', $slug, Arr::get($checks, $slug, ''));
        }

        return implode("\n", $lines);
    }
}
