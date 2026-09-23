<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Enums\AuditType;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class PromptTemplates
{
    public const string PUBLISHED_PATH = 'audits/vendor/laravel-audit';

    private const string OLD_PROMPTS_PATH = 'prompts/vendor/laravel-audit';

    /** @param  string|null  $overridePath  published prompts; files there win */
    public function __construct(private ?string $overridePath = null) {}

    public static function publishedPath(): ?string
    {
        return function_exists('resource_path') ? resource_path(self::PUBLISHED_PATH) : null;
    }

    /**
     * Published prompts and checks of an older layout, which are not read
     * any more: everything below resources/prompts/vendor/laravel-audit and
     * the flat resources/audits/vendor/laravel-audit/<audit>.php files.
     *
     * @return list<string> paths relative to the resources directory
     */
    public static function outdated(string $resourcesPath): array
    {
        $files = [];
        $old = $resourcesPath.'/'.self::OLD_PROMPTS_PATH;

        if (is_dir($old)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = self::OLD_PROMPTS_PATH.'/'.substr((string) $file, strlen($old) + 1);
            }
        }

        foreach (AuditType::values() as $audit) {
            if (is_file($resourcesPath.'/'.self::PUBLISHED_PATH.'/'.$audit.'.php')) {
                $files[] = self::PUBLISHED_PATH.'/'.$audit.'.php';
            }
        }

        sort($files);

        return $files;
    }

    public static function packagePath(): string
    {
        return dirname(__DIR__, 2).'/resources/audits';
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
        foreach (array_filter([$this->overridePath, self::packagePath()]) as $directory) {
            if (is_file($directory.'/'.$file)) {
                return $directory.'/'.$file;
            }
        }

        return null;
    }
}
