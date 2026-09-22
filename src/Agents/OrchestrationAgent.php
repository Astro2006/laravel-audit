<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use Astro2006\LaravelAudit\Support\Language;

final class OrchestrationAgent
{
    public const string KEY = 'orchestrator';

    private const string TEMPLATE = 'agents/_orchestrator.md';

    private readonly PromptTemplates $templates;

    /** @param  string|null  $overridePath  published prompts; files there win */
    public function __construct(?string $overridePath = null)
    {
        $this->templates = new PromptTemplates($overridePath);
    }

    /** @param  list<string>  $audits  the selected audits */
    public function render(string $projectName, string $runId, array $audits, bool $force, int $waitTimeout, string $reportLanguage): RenderedPrompt
    {
        $text = strtr($this->templates->get(self::TEMPLATE), [
            '{{project_name}}' => $projectName,
            '{{run_id}}' => $runId,
            '{{audits}}' => implode(', ', array_map(fn (string $audit): string => '`'.$audit.'`', $audits)),
            '{{force_note}}' => $force ? ' The user started the run with --force: failing tests do not stop it.' : '',
            '{{wait_timeout}}' => (string) $waitTimeout,
            '{{report_language}}' => Language::label($reportLanguage),
        ]);

        return new RenderedPrompt($text, $this->hash());
    }

    public function hash(): string
    {
        return $this->templates->hash(self::TEMPLATE);
    }
}
