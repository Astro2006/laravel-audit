<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class BrowserSnapshot extends JunitSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::Browser;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'tests/browser-condensed.json');

        if ($report === null) {
            return null;
        }

        $files = [];
        $artifacts = Read::strings($report, 'artifacts');

        if ($artifacts !== []) {
            $files[] = new SnapshotFile('screenshots', 'Screenshots of failures', Details::list(array_map(
                fn (string $path): string => '`'.$displayDir.'/'.$path.'`',
                $artifacts,
            )));
        }

        $visited = [];

        foreach (Read::array($report, 'visited') as $file => $uris) {
            $visited[] = sprintf('`%s`: %s', $file, implode(', ', array_map('strval', (array) $uris)) ?: 'none');
        }

        if ($visited !== []) {
            $files[] = new SnapshotFile('visited', 'Visited URIs per test file', Details::list($visited));
        }

        return $this->manifest('Browser tests in `'.Read::string($report, 'path', 'tests/Browser').'`', $report, $files);
    }
}
