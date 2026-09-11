<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Phpstan\BaselineEntry;
use Astro2006\LaravelAudit\Data\Phpstan\FileCount;
use Astro2006\LaravelAudit\Data\Phpstan\IdentifierGroup;
use Astro2006\LaravelAudit\Data\Phpstan\PhpstanMessage;
use Astro2006\LaravelAudit\Data\Phpstan\PhpstanReport;
use Illuminate\Support\Collection;

/**
 * Groups PHPStan errors by identifier and file, and adds the baseline
 * entries as "baselined" so hidden errors stay visible.
 */
final class PhpstanGrouper
{
    /**
     * @param  list<PhpstanMessage>  $messages
     * @param  list<BaselineEntry>  $baseline
     * @param  list<string>  $generalErrors
     */
    public function group(string $level, array $messages, array $baseline, array $generalErrors = []): PhpstanReport
    {
        $identifiers = (new Collection($messages))
            ->groupBy(fn (PhpstanMessage $message): string => $message->identifier)
            ->map(fn (Collection $group, int|string $identifier): IdentifierGroup => new IdentifierGroup(
                identifier: (string) $identifier,
                count: $group->count(),
                fileCount: $group->unique(fn (PhpstanMessage $message): string => $message->file)->count(),
                topFiles: $this->topFiles($group->map(fn (PhpstanMessage $message): FileCount => new FileCount($message->file, 1))),
                examples: $group->take(3)->values()->all(),
            ));

        $baselined = (new Collection($baseline))
            ->groupBy(fn (BaselineEntry $entry): string => $entry->identifier ?? 'unknown')
            ->map(fn (Collection $group, int|string $identifier): IdentifierGroup => new IdentifierGroup(
                identifier: (string) $identifier,
                count: (int) $group->sum(fn (BaselineEntry $entry): int => $entry->count),
                fileCount: $group->unique(fn (BaselineEntry $entry): string => $entry->path)->count(),
                topFiles: $this->topFiles($group->map(fn (BaselineEntry $entry): FileCount => new FileCount($entry->path, $entry->count))),
                examples: [],
                baselined: true,
            ));

        return new PhpstanReport(
            level: $level,
            errors: count($messages),
            baselined: array_sum(array_map(fn (BaselineEntry $entry): int => $entry->count, $baseline)),
            identifiers: $this->sorted($identifiers),
            baselinedIdentifiers: $this->sorted($baselined),
            generalErrors: $generalErrors,
        );
    }

    /**
     * @param  Collection<int, FileCount>  $counts
     * @return list<FileCount>
     */
    private function topFiles(Collection $counts): array
    {
        return $counts
            ->groupBy(fn (FileCount $count): string => $count->file)
            ->map(fn (Collection $group, int|string $file): FileCount => new FileCount((string) $file, (int) $group->sum(fn (FileCount $count): int => $count->count)))
            ->sortByDesc(fn (FileCount $count): int => $count->count)
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<array-key, IdentifierGroup>  $groups
     * @return list<IdentifierGroup>
     */
    private function sorted(Collection $groups): array
    {
        return $groups->sortByDesc(fn (IdentifierGroup $group): int => $group->count)->values()->all();
    }
}
