<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Contracts\Snapshot;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Json;

abstract class PreflightSnapshot implements Snapshot
{
    /** @return array<mixed>|null null when the file is missing or empty */
    protected function read(PreflightSummary $preflight, string $file): ?array
    {
        if ($preflight->directory === null || ! is_file($preflight->directory.'/'.$file)) {
            return null;
        }

        $data = Json::readFile($preflight->directory.'/'.$file);

        return $data === [] ? null : $data;
    }

    /**
     * The first few items and how many more there are: "a.php, b.php +3".
     *
     * @param  list<string>  $items
     */
    protected static function few(array $items, int $max = 2): string
    {
        $more = count($items) - $max;

        return implode(', ', array_slice($items, 0, $max)).($more > 0 ? ' +'.$more : '');
    }

    protected static function shortClass(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
