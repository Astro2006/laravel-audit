<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;

/**
 * --reuse-preflight: finds an earlier run and copies its preflight into the
 * new run folder (a copy, not a symlink, so it works on Windows).
 */
final class PreflightReuse
{
    /**
     * @return array{run: string, manifest: array<string, string>}|null
     */
    public function find(string $outputRoot, ?string $runId, string $excludeRunId): ?array
    {
        if ($runId !== null && $runId !== '') {
            $manifest = RunManifest::read($outputRoot.'/'.$runId.'/run.md');

            return is_dir($outputRoot.'/'.$runId.'/preflight') ? ['run' => $runId, 'manifest' => $manifest] : null;
        }

        $candidates = [];

        foreach (glob($outputRoot.'/*/run.md') ?: [] as $path) {
            $manifest = RunManifest::read($path);
            $id = basename(dirname($path));

            if ($id !== $excludeRunId && ($manifest['preflight'] ?? '') === 'complete') {
                $candidates[$manifest['started_at'] ?? $id] = ['run' => $id, 'manifest' => $manifest];
            }
        }

        if ($candidates === []) {
            return null;
        }

        krsort($candidates);

        return reset($candidates);
    }

    /**
     * Steps that the selection needs but the old preflight lacks.
     *
     * @param  list<PreflightStep>  $required
     * @return list<string>
     */
    public function missingSteps(PreflightSummary $summary, array $required): array
    {
        return array_values(array_map(
            fn (PreflightStep $step): string => $step->value,
            array_filter($required, fn (PreflightStep $step): bool => $summary->status($step) === null),
        ));
    }

    public function copy(string $from, string $to): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        if (! is_dir($to)) {
            mkdir($to, 0777, true);
        }

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
