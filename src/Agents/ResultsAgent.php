<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Prompts\PromptContext;
use Astro2006\LaravelAudit\Prompts\RenderedPrompt;
use RuntimeException;

/**
 * Writes the run's overview: one agent call after every selected audit is
 * done, reading their summaries and finding counts to spot cross-audit
 * patterns a single audit cannot see on its own.
 */
final class ResultsAgent
{
    private const string TEMPLATE = 'agents/_results.md';

    /** @param  string|null  $overridePath  published prompts; files there win */
    public function __construct(private readonly ?string $overridePath = null) {}

    /** @param  list<string>  $auditDigests  one Markdown block per audit: its title, summary and finding counts */
    public function render(PromptContext $context, array $auditDigests): RenderedPrompt
    {
        $text = strtr($this->template(self::TEMPLATE), [
            '{{project_name}}' => $context->projectName,
            '{{laravel_version}}' => $context->laravelVersion,
            '{{run_id}}' => $context->runId,
            '{{report_language}}' => Agent::languageLabel($context->reportLanguage),
            '{{audits_digest}}' => $auditDigests === [] ? 'No audits ran.' : implode("\n\n", $auditDigests),
        ]);

        return new RenderedPrompt($text, $this->hash());
    }

    /** Depends on the template only, never on the run. */
    public function hash(): string
    {
        return substr(sha1($this->template(self::TEMPLATE)), 0, 8);
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
