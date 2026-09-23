<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class TranslationsSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::Translations;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $report = $this->read($preflight, 'translations.json');

        if ($report === null) {
            return null;
        }

        $missing = Read::arrays($report, 'missing');
        $total = array_sum(array_map(fn (array $locale): int => Read::int($locale, 'count'), $missing));

        return new SnapshotManifest(
            SnapshotType::Translations,
            sprintf('Translations — %d missing keys', $total),
            [
                'Directory `'.Read::string($report, 'directory', 'lang').'`',
                'Default '.Read::string($report, 'default', '?'),
                'Locales '.implode(', ', Read::strings($report, 'locales')),
            ],
            ['Locale', 'Missing keys'],
            array_map(fn (array $locale): SnapshotRow => new SnapshotRow(
                [Read::string($locale, 'locale'), (string) Read::int($locale, 'count')],
                Details::list(array_map(fn (string $key): string => '`'.$key.'`', Read::strings($locale, 'keys'))),
            ), $missing),
        );
    }
}
