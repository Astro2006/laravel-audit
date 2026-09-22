<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final class Files
{
    /**
     * Every file below a directory, recursively; none when it does not exist.
     *
     * @return list<\SplFileInfo>
     */
    public static function below(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
