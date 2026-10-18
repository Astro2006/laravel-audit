<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Run\Data\RunFrontmatter;

final class PreflightReuse
{
    public function find(string $outputRoot, ?string $runId, string $excludeRunId): ?RunFrontmatter
    {
        if ($runId !== null && $runId !== '') {
            if (! is_dir($outputRoot.'/'.$runId.'/preflight')) {
                return null;
            }

            return RunFrontmatter::read($outputRoot.'/'.$runId.'/run.md') ?? new RunFrontmatter(runId: $runId);
        }

        $candidates = [];

        foreach (glob($outputRoot.'/*/run.md') ?: [] as $path) {
            $manifest = RunFrontmatter::read($path);

            if ($manifest !== null && $manifest->preflightComplete && basename(dirname($path)) !== $excludeRunId) {
                $candidates[] = new RunFrontmatter(
                    runId: basename(dirname($path)),
                    status: $manifest->status,
                    startedAt: $manifest->startedAt,
                    preflightComplete: true,
                    preflightFrom: $manifest->preflightFrom,
                    model: $manifest->model,
                    gitCommit: $manifest->gitCommit,
                    gitDirty: $manifest->gitDirty,
                    audits: $manifest->audits,
                );
            }
        }

        usort($candidates, fn (RunFrontmatter $a, RunFrontmatter $b): int => [$b->startedAt, $b->runId] <=> [$a->startedAt, $a->runId]);

        return $candidates === [] ? null : reset($candidates);
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
            array_filter($required, fn (PreflightStep $step): bool => $summary->steps->status($step) === null),
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
