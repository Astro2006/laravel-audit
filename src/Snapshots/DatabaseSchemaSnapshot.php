<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class DatabaseSchemaSnapshot extends PreflightSnapshot
{
    public function type(): SnapshotType
    {
        return SnapshotType::DatabaseSchema;
    }

    public function build(PreflightSummary $preflight, string $displayDir): ?SnapshotManifest
    {
        $schema = $this->read($preflight, 'database/schema.json');

        if ($schema === null) {
            return null;
        }

        $tables = Read::array($schema, 'tables');
        uksort($tables, fn (string $a, string $b): int => [Read::strings(Arr::get($tables, $a), 'flags') === [], $a] <=> [Read::strings(Arr::get($tables, $b), 'flags') === [], $b]);
        $migrations = Read::arrays($schema, 'migrations');
        $flaggedMigrations = array_values(array_filter($migrations, fn (array $migration): bool => Read::strings($migration, 'flags') !== []));

        return new SnapshotManifest(
            SnapshotType::DatabaseSchema,
            sprintf('Database schema — %d tables from %d migrations (static read)', count($tables), count($migrations)),
            [
                ...array_map(fn (array $migration): string => sprintf('`%s`: %s', Read::string($migration, 'file'), implode(', ', Read::strings($migration, 'flags'))), array_slice($flaggedMigrations, 0, 10)),
            ],
            ['Table', 'Columns', 'Indexed', 'Foreign keys', 'Candidates'],
            array_map(fn (string $name): SnapshotRow => $this->row($name, Read::array($tables, $name)), array_map('strval', array_keys($tables))),
        );
    }

    /** @param array<mixed> $table */
    private function row(string $name, array $table): SnapshotRow
    {
        $columns = Read::stringMap($table, 'columns');
        $flags = Read::strings($table, 'flags');

        return new SnapshotRow(
            ['`'.$name.'`', (string) count($columns), (string) (count(Read::strings($table, 'indexed')) + count(Read::strings($table, 'unique'))), (string) count(Read::strings($table, 'foreign')), $flags === [] ? '–' : self::few($flags, 1)],
            Details::fields([
                'Migrations' => Read::strings($table, 'migrations'),
                'Columns' => array_map(fn (string $column, string $type): string => '`'.$column.'` '.$type, array_keys($columns), $columns),
                'Indexed' => Read::strings($table, 'indexed'),
                'Unique' => Read::strings($table, 'unique'),
                'Foreign keys' => Read::strings($table, 'foreign'),
                'Candidates' => $flags,
            ]),
        );
    }
}
