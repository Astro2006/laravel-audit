<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DeniedFiles
{
    /**
     * @param  list<string>  $deny  globs relative to the project root; `**` matches any number of directories
     * @param  list<string>  $allow  exact relative paths that stay readable
     * @return list<string>
     */
    public static function resolve(string $basePath, array $deny, array $allow): array
    {
        $files = [];
        $base = rtrim($basePath, '/').'/';

        foreach ($deny as $pattern) {
            $pattern = ltrim($pattern, '/');
            $matches = str_contains($pattern, '**') ? self::recursive($base, $pattern) : (glob($base.$pattern) ?: []);

            foreach ($matches as $path) {
                if (is_file($path)) {
                    $files[] = substr($path, strlen($base));
                }
            }
        }

        $allowed = array_map(fn (string $path): string => str_starts_with($path, './') ? substr($path, 2) : $path, $allow);
        $files = array_values(array_unique(array_diff($files, $allowed)));
        sort($files);

        return $files;
    }

    /**
     * Walks the directory before the first wildcard and matches every file
     * against the pattern, so `storage/**\/*.key` also finds `storage/app/x.key`.
     *
     * @return list<string>
     */
    private static function recursive(string $base, string $pattern): array
    {
        $prefix = '';

        foreach (explode('/', $pattern) as $segment) {
            if (strpbrk($segment, '*?[') !== false) {
                break;
            }

            $prefix .= $segment.'/';
        }

        if (! is_dir($base.$prefix)) {
            return [];
        }

        $regex = self::regex($pattern);
        $paths = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.$prefix, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && preg_match($regex, substr($file->getPathname(), strlen($base))) === 1) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    private static function regex(string $pattern): string
    {
        $regex = strtr(preg_quote($pattern, '#'), [
            '\*\*/' => '(?:.*/)?',
            '\*\*' => '.*',
            '\*' => '[^/]*',
            '\?' => '[^/]',
        ]);

        return '#^'.$regex.'$#';
    }
}
