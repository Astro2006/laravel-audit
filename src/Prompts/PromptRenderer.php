<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Contracts\Audit;
use RuntimeException;

/**
 * Final prompt = _base.md + {audit}.md with placeholders replaced.
 *
 * Placeholders are replaced with strtr(), not Blade: the prompts contain
 * Blade syntax like {!! !!} and @csrf as subject matter.
 */
final class PromptRenderer
{
    private const array LANGUAGES = [
        'de' => 'German (de)',
        'en' => 'English (en)',
        'fr' => 'French (fr)',
        'es' => 'Spanish (es)',
        'it' => 'Italian (it)',
        'nl' => 'Dutch (nl)',
        'pt' => 'Portuguese (pt)',
        'pl' => 'Polish (pl)',
    ];

    /**
     * @param  string  $packagePath  resources/prompts of this package
     * @param  string|null  $overridePath  published prompts; files there win
     */
    public function __construct(
        private readonly string $packagePath,
        private readonly ?string $overridePath = null,
    ) {}

    public function render(Audit $audit, PromptContext $context): RenderedPrompt
    {
        $base = $this->template('_base.md');
        $specific = $this->template($audit->promptFile());

        $hash = substr(sha1($base."\0".$specific."\0".json_encode($audit->checks())), 0, 8);

        $text = strtr($base."\n\n".$specific, $this->placeholders($audit, $context));

        return new RenderedPrompt($text, $hash);
    }

    public static function languageLabel(string $locale): string
    {
        $short = strtolower(substr($locale, 0, 2));

        return self::LANGUAGES[$short] ?? $locale;
    }

    /** @return array<string, string> */
    private function placeholders(Audit $audit, PromptContext $context): array
    {
        $customChecks = $context->customChecks[$audit->type()->value] ?? [];

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
            '{{report_language}}' => self::languageLabel($context->reportLanguage),
            '{{exclude_paths}}' => implode(', ', array_map(fn (string $path): string => '`'.$path.'`', $context->excludePaths)),
            '{{max_findings}}' => (string) $context->maxFindings,
            '{{checks}}' => $this->checkList($audit),
            '{{custom_checks}}' => $customChecks === []
                ? 'None.'
                : implode("\n", array_map(fn (string $rule): string => '- '.$rule.' (use check `custom`)', $customChecks)),
            '{{boost_note}}' => $context->boostAvailable
                ? 'Laravel Boost MCP tools are available (application info, database schema, routes, config, docs search). Use them before making assumptions, and search the Laravel docs via Boost when you are unsure whether something is idiomatic for this Laravel version.'
                : 'Laravel Boost is not available in this run. Work from the files.',
        ];
    }

    private function checkList(Audit $audit): string
    {
        $lines = [];

        foreach ($audit->checks() as $slug => $description) {
            $lines[] = sprintf('- `%s` — %s', $slug, $description);
        }

        return implode("\n", $lines);
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
