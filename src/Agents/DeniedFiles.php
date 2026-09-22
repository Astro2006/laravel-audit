<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

final class DeniedFiles
{
    /**
     * @param  list<string>  $deny  globs relative to the project root
     * @param  list<string>  $allow  exact relative paths that stay readable
     * @return list<string>
     */
    public static function resolve(string $basePath, array $deny, array $allow): array
    {
        $files = [];
        $base = rtrim($basePath, '/').'/';

        foreach ($deny as $pattern) {
            foreach (glob($base.ltrim($pattern, '/')) ?: [] as $path) {
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
}
