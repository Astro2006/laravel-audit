<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Regex;

/**
 * The schema as the migrations build it, read from the files without a
 * database: tables, columns, indexes, foreign keys, and candidates for the
 * database task. A static read misses dynamic migrations; the agent confirms.
 */
final class MigrationSchema
{
    private const string DIRECTORY = 'database/migrations';

    /** Column types that hold a reference to another table. */
    private const array KEY_TYPES = ['foreignId', 'foreignUuid', 'foreignUlid', 'unsignedBigInteger', 'bigInteger', 'unsignedInteger', 'integer', 'uuid', 'ulid'];

    /** Column names that usually hold money. */
    private const string MONEY = '/(^|_)(price|amount|total|cost|balance|fee|salary|revenue)(_|$)/';

    /** Column names that usually hold a date. */
    private const string DATE = '/(_at|_on|_date|^date)$/';

    /**
     * @return array{migrations: list<array{file: string, tables: list<string>, flags: list<string>}>, tables: array<string, array{migrations: list<string>, columns: array<string, string>, indexed: list<string>, foreign: list<string>, unique: list<string>, flags: list<string>}>}
     */
    public function read(ProjectInspector $project): array
    {
        $migrations = [];
        $tables = [];

        foreach ($this->files($project) as $file) {
            $code = (string) file_get_contents($project->path($file));
            $touched = [];

            foreach ($this->blocks($code) as [$table, $block]) {
                $touched[] = $table;
                $tables[$table] ??= ['migrations' => [], 'columns' => [], 'indexed' => [], 'foreign' => [], 'unique' => [], 'flags' => []];
                $tables[$table]['migrations'][] = $file;
                $this->columns($block, $tables[$table]);
            }

            $migrations[] = ['file' => $file, 'tables' => array_values(array_unique($touched)), 'flags' => $this->migrationFlags($code)];
        }

        foreach ($tables as $name => $table) {
            $tables[$name]['flags'] = $this->tableFlags($table);
        }

        ksort($tables);

        return ['migrations' => $migrations, 'tables' => $tables];
    }

    /** @return list<string> migration files in the order they run */
    private function files(ProjectInspector $project): array
    {
        $files = array_values(array_filter(
            $project->phpFiles(self::DIRECTORY),
            fn (string $file): bool => dirname($file) === self::DIRECTORY,
        ));
        sort($files);

        return $files;
    }

    /**
     * The body of every Schema::create / Schema::table call with its table.
     *
     * @return list<array{string, string}>
     */
    private function blocks(string $code): array
    {
        $parts = preg_split('/Schema::(?:create|table)\(\s*/', $code) ?: [];
        $blocks = [];

        foreach (array_slice($parts, 1) as $part) {
            $table = Regex::groups('/^[\'"]([^\'"]+)[\'"]/', $part);

            if ($table !== null) {
                // Up to the next Schema:: call or the end of the method.
                $blocks[] = [$table[0], (string) preg_replace('/\n\s*}\s*\n\s*(public|protected|private)?\s*function.*$/s', '', $part)];
            }
        }

        return $blocks;
    }

    /**
     * @param  array{migrations: list<string>, columns: array<string, string>, indexed: list<string>, foreign: list<string>, unique: list<string>, flags: list<string>}  $table
     */
    private function columns(string $block, array &$table): void
    {
        preg_match_all('/\$table->(\w+)\(\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])?[^;]*;/', $block, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$statement, $method] = [$match[0], $match[1]];
            $names = Regex::all('/[\'"]([^\'"]+)[\'"]/', $match[2] ?? '');

            if ($method === 'id' && $names === []) {
                $names = ['id']; // $table->id() without a name
            }

            if (in_array($method, ['index', 'unique', 'primary'], true)) {
                array_push($table[$method === 'unique' ? 'unique' : 'indexed'], ...array_slice($names, 0, 1));

                continue;
            }

            if ($method === 'foreign') {
                array_push($table['foreign'], ...array_slice($names, 0, 1));

                continue;
            }

            if (in_array($method, ['morphs', 'nullableMorphs', 'uuidMorphs', 'ulidMorphs'], true) && $names !== []) {
                $table['columns'][$names[0].'_id'] = $method;
                $table['indexed'][] = $names[0].'_id';

                continue;
            }

            if ($names === [] || str_starts_with($method, 'drop') || str_starts_with($method, 'rename')) {
                continue;
            }

            $column = $names[0];
            $table['columns'][$column] = $method;

            if (str_contains($statement, '->constrained(') || str_contains($statement, '->references(')) {
                $table['foreign'][] = $column;
            }

            if (str_contains($statement, '->unique(')) {
                $table['unique'][] = $column;
            }

            if (str_contains($statement, '->index(') || str_contains($statement, '->primary(') || $method === 'id') {
                $table['indexed'][] = $column;
            }
        }
    }

    /**
     * @param  array{migrations: list<string>, columns: array<string, string>, indexed: list<string>, foreign: list<string>, unique: list<string>, flags: list<string>}  $table
     * @return list<string>
     */
    private function tableFlags(array $table): array
    {
        $flags = [];
        $indexed = [...$table['indexed'], ...$table['unique']];

        foreach ($table['columns'] as $column => $type) {
            $reference = str_ends_with($column, '_id') && in_array($type, self::KEY_TYPES, true);

            if ($reference && ! in_array($column, $table['foreign'], true)) {
                $flags[] = sprintf('`%s` has no foreign key constraint', $column);
            }

            // MySQL indexes foreign keys itself; PostgreSQL and SQLite do not.
            if ($reference && ! in_array($column, $indexed, true)) {
                $flags[] = sprintf('`%s` has no explicit index', $column);
            }

            if (in_array($type, ['float', 'double'], true) && preg_match(self::MONEY, $column) === 1) {
                $flags[] = sprintf('`%s` holds money as %s', $column, $type);
            }

            if (in_array($type, ['string', 'char', 'text'], true) && preg_match(self::DATE, $column) === 1) {
                $flags[] = sprintf('`%s` holds a date as %s', $column, $type);
            }
        }

        return $flags;
    }

    /** @return list<string> */
    private function migrationFlags(string $code): array
    {
        $flags = [];
        $down = Regex::groups('/function\s+down\s*\(\s*\)\s*(?::\s*void\s*)?\{(.*?)\n\s{4}\}/s', $code);

        if (str_contains($code, 'function up') && $down === null) {
            $flags[] = 'no down()';
        } elseif ($down !== null && trim((string) preg_replace('/\/\/.*$/m', '', $down[0])) === '') {
            $flags[] = 'empty down()';
        }

        $up = Regex::groups('/function\s+up\s*\(\s*\)\s*(?::\s*void\s*)?\{(.*?)\n\s{4}\}/s', $code);
        $up = $up === null ? '' : $up[0];

        if (preg_match('/->drop(Column|Columns)?\(|Schema::drop(IfExists)?\(/', $up) === 1) {
            $flags[] = 'drops data in up()';
        }

        if (preg_match('/\bApp\\\\Models\\\\|DB::(table|statement|update|insert|delete)\(/', $up) === 1) {
            $flags[] = 'changes data in up()';
        }

        return $flags;
    }
}
