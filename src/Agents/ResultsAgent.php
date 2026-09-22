<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Support\Language;

/**
 * Writes the run's overview: one agent call after every selected audit is
 * done, reading their summaries and finding counts to spot cross-audit
 * patterns a single audit cannot see on its own.
 */
final class ResultsAgent
{
    private const string TEMPLATE = 'agents/_results.md';

    private readonly PromptTemplates $templates;

    /** @param  string|null  $overridePath  published prompts; files there win */
    public function __construct(?string $overridePath = null)
    {
        $this->templates = new PromptTemplates($overridePath);
    }

    /** @param  list<string>  $auditDigests  one Markdown block per audit: its title, summary and finding counts */
    public function render(PromptContext $context, array $auditDigests): RenderedPrompt
    {
        $text = strtr($this->templates->get(self::TEMPLATE), [
            '{{project_name}}' => $context->projectName,
            '{{laravel_version}}' => $context->laravelVersion,
            '{{run_id}}' => $context->runId,
            '{{report_language}}' => Language::label($context->reportLanguage),
            '{{audits_digest}}' => $auditDigests === [] ? 'No audits ran.' : implode("\n\n", $auditDigests),
        ]);

        return new RenderedPrompt($text, $this->hash());
    }

    /** Depends on the template only, never on the run. */
    public function hash(): string
    {
        return $this->templates->hash(self::TEMPLATE);
    }
}
