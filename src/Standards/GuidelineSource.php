<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Standards;

use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Regex;
use FilesystemIterator;
use Illuminate\Support\Arr;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class GuidelineSource
{
    private const array TOOLING_BLOCKS = ['foundation', 'boost', 'herd', 'deployments', 'tests'];

    private const string INTRO = "The project's coding standards: Laravel Boost's guidelines for its stack and the project's own rules. Judge the code against them and the conventions baseline. A rule the project follows is not a finding; group one finding per violated rule.";

    /** @param  array<string, string>  $blocks  name => markdown */
    public function __construct(public array $blocks) {}

    /**
     * `agent.standards`: `boost` reads the rule blocks Boost wrote into
     * AGENTS.md or CLAUDE.md plus `.ai/rules` and `.ai/guidelines`, `none`
     * reads nothing, a list names files relative to the project.
     *
     * @return self the standards of the project, possibly empty
     */
    public static function for(string $basePath, AuditConfig $config): self
    {
        $mode = $config->get('agent.standards');
        $basePath = rtrim($basePath, '/');

        if ($mode === 'none') {
            return new self([]);
        }

        if (is_array($mode)) {
            $blocks = [];

            foreach (array_map('strval', $mode) as $file) {
                if (is_file($basePath.'/'.$file)) {
                    $blocks[$file] = (string) file_get_contents($basePath.'/'.$file);
                }
            }

            return new self($blocks);
        }

        return new self([...self::boostBlocks($basePath), ...self::projectFiles($basePath, '.ai/rules', 'rules'), ...self::projectFiles($basePath, '.ai/guidelines', 'guidelines')]);
    }

    /** @return string the `## Coding standards` section of a task prompt, or nothing when there are no standards */
    public function render(int $maxChars): string
    {
        if ($this->blocks === []) {
            return '';
        }

        $parts = [];

        foreach ($this->blocks as $name => $text) {
            $parts[] = "### {$name}\n\n".trim($text);
        }

        $body = implode("\n\n", $parts);

        if (mb_strlen($body) > $maxChars) {
            $body = rtrim(mb_substr($body, 0, $maxChars))."\n\n(Standards shortened to {$maxChars} characters.)";
        }

        return "## Coding standards\n\n".self::INTRO."\n\n".$body;
    }

    /** @return array<string, string> */
    private static function boostBlocks(string $basePath): array
    {
        foreach (['AGENTS.md', 'CLAUDE.md'] as $file) {
            $section = is_file($basePath.'/'.$file) ? Regex::groups('/<laravel-boost-guidelines>(.*?)<\/laravel-boost-guidelines>/s', (string) file_get_contents($basePath.'/'.$file)) : null;

            if ($section === null) {
                continue;
            }

            [$inner] = $section;
            preg_match_all('/^=== (.+?) rules ===\n(.*?)(?=^=== .+? rules ===|\z)/ms', $inner, $matches, PREG_SET_ORDER);
            $blocks = [];

            foreach ($matches as $match) {
                $name = trim((string) Arr::get($match, 1));

                if (! in_array($name, self::TOOLING_BLOCKS, true) && ! str_contains($name, 'laravel-audit')) {
                    $blocks[$name] = trim((string) Arr::get($match, 2));
                }
            }

            return $blocks;
        }

        return [];
    }

    /** @return array<string, string> */
    private static function projectFiles(string $basePath, string $directory, string $prefix): array
    {
        if (! is_dir($basePath.'/'.$directory)) {
            return [];
        }

        $blocks = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'md' || $file->getBasename() === 'index.md') {
                continue;
            }

            $blocks[$prefix.'/'.substr($file->getPathname(), strlen($basePath.'/'.$directory) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($blocks);

        return $blocks;
    }
}
