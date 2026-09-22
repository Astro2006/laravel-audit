<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Agents\DependenciesAgent;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;

final class ComposerSnapshot extends PreflightSnapshot
{
    private const array KINDS = [
        'advisories', 'abandoned', 'outdated_major', 'unused_candidates', 'misplaced_candidates',
        'shadow_candidates', 'licenses_flagged', 'constraints_flagged',
    ];

    public function type(): SnapshotType
    {
        return SnapshotType::Composer;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $facts = $this->read($preflight, DependenciesAgent::FACTS_FILE);

        if ($facts === null) {
            return null;
        }

        $usage = $this->read($preflight, 'composer/usage-map.json') ?? [];
        $rows = [];

        foreach (self::KINDS as $kind) {
            foreach (Read::arrays($facts, $kind) as $item) {
                $package = Read::string($item, 'package');

                $rows[] = new SnapshotRow(
                    [$kind, $package, $this->info($kind, $item)],
                    implode("\n\n", array_filter([
                        Details::fields(['Kind' => $kind, ...$item]),
                        $this->usage($usage, $package),
                    ])),
                );
            }
        }

        $validate = Read::array($facts, 'validate');
        $count = fn (string $kind): string => $kind.' '.count(Read::arrays($facts, $kind));

        return new SnapshotManifest(
            SnapshotType::Composer,
            sprintf('Composer — %d advisories · %d abandoned · %d outdated majors', count(Read::arrays($facts, 'advisories')), count(Read::arrays($facts, 'abandoned')), count(Read::arrays($facts, 'outdated_major'))),
            [
                ...array_map($count, self::KINDS),
                'lock in sync '.(Read::bool($validate, 'lock_in_sync', true) ? 'yes' : 'no'),
                'validate '.(Read::bool($validate, 'ok', true) ? 'ok' : 'failed'),
            ],
            ['Kind', 'Package', 'Info'],
            $rows,
            [new SnapshotFile('composer', 'composer validate and Composer config', implode("\n\n", array_filter([
                Details::fields(['Config' => Read::array($facts, 'composer_config')]),
                "**composer validate**\n\n".Details::text(Read::string($validate, 'output') ?: 'no output'),
            ])))],
        );
    }

    /** @param  array<mixed>  $item */
    private function info(string $kind, array $item): string
    {
        return match ($kind) {
            'advisories' => trim(Read::string($item, 'severity').' '.(Read::nullableString($item, 'cve') ?? Read::string($item, 'advisory'))),
            'abandoned' => Read::nullableString($item, 'replacement') === null ? 'no replacement' : '→ '.Read::string($item, 'replacement'),
            'outdated_major' => Read::string($item, 'installed').' → '.Read::string($item, 'latest'),
            'licenses_flagged' => Read::string($item, 'license'),
            'constraints_flagged' => Read::string($item, 'constraint'),
            default => trim(Read::string($item, 'section').' '.Read::string($item, 'suggestion')),
        };
    }

    /** @param  array<mixed>  $usage */
    private function usage(array $usage, string $package): string
    {
        $entry = Read::array(Read::array($usage, 'packages'), $package) ?: Read::array(Read::array($usage, 'transitive_usage'), $package);

        return $entry === [] ? '' : "**Usage**\n\n".Details::fields($entry);
    }
}
