<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use RuntimeException;

final readonly class PromptTemplates
{
    public const string PUBLISHED_PATH = 'prompts/vendor/laravel-audit';

    private const array OUTDATED = ['_base.md', 'tests.md', 'frontend.md', 'backend.md', 'dependencies.md', 'summary.md'];

    /** @param  string|null  $overridePath  published prompts; files there win */
    public function __construct(private ?string $overridePath = null) {}

    public static function publishedPath(): ?string
    {
        return function_exists('resource_path') ? resource_path(self::PUBLISHED_PATH) : null;
    }

    /**
     * Published copies of prompts the task layout no longer reads.
     *
     * @return list<string>
     */
    public static function outdated(?string $overridePath): array
    {
        if ($overridePath === null) {
            return [];
        }

        return array_values(array_filter(self::OUTDATED, fn (string $file): bool => is_file($overridePath.'/'.$file)));
    }

    public function has(string $file): bool
    {
        return $this->path($file) !== null;
    }

    public function get(string $file): string
    {
        $path = $this->path($file) ?? throw new RuntimeException("Prompt template [{$file}] not found.");

        return (string) file_get_contents($path);
    }

    public function hash(string $file): string
    {
        return substr(sha1($this->get($file)), 0, 8);
    }

    private function path(string $file): ?string
    {
        foreach (array_filter([$this->overridePath, dirname(__DIR__, 2).'/resources/prompts']) as $directory) {
            if (is_file($directory.'/'.$file)) {
                return $directory.'/'.$file;
            }
        }

        return null;
    }
}
