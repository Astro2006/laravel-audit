<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Arr;

final class MigrationSchema
{
    private const string DIRECTORY = 'database/migrations';

    private const array KEY_TYPES = ['foreignId', 'foreignUuid', 'foreignUlid', 'unsignedBigInteger', 'bigInteger', 'unsignedInteger', 'integer', 'uuid', 'ulid'];

    private const string MONEY = '/(^|_)(price|amount|total|cost|balance|fee|salary|revenue)(_|$)/';

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
                $entry = Arr::get($tables, $table, ['migrations' => [], 'columns' => [], 'indexed' => [], 'foreign' => [], 'unique' => [], 'flags' => []]);
                $entry['migrations'][] = $file;
                $this->columns($block, $entry);
                $tables[$table] = $entry;
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
                $blocks[] = [Arr::first($table), (string) preg_replace('/\n\s*}\s*\n\s*(public|protected|private)?\s*function.*$/s', '', $part)];
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
            [$statement, $method] = $match;
            $names = Regex::all('/[\'"]([^\'"]+)[\'"]/', Arr::get($match, 2, ''));

            if ($method === 'id' && $names === []) {
                $names = ['id'];
            }

            $name = Arr::first($names);

            if (in_array($method, ['index', 'unique', 'primary'], true)) {
                if ($name !== null) {
                    $table[$method === 'unique' ? 'unique' : 'indexed'][] = $name;
                }

                continue;
            }

            if ($method === 'foreign') {
                if ($name !== null) {
                    $table['foreign'][] = $name;
                }

                continue;
            }

            if (in_array($method, ['morphs', 'nullableMorphs', 'uuidMorphs', 'ulidMorphs'], true) && $name !== null) {
                $table['columns'][$name.'_id'] = $method;
                $table['indexed'][] = $name.'_id';

                continue;
            }

            if ($name === null || str_starts_with($method, 'drop') || str_starts_with($method, 'rename')) {
                continue;
            }

            $column = $name;
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
        $indexed = [...Arr::get($table, 'indexed'), ...Arr::get($table, 'unique')];

        foreach (Arr::get($table, 'columns') as $column => $type) {
            $reference = str_ends_with($column, '_id') && in_array($type, self::KEY_TYPES, true);

            if ($reference && ! in_array($column, Arr::get($table, 'foreign'), true)) {
                $flags[] = sprintf('`%s` has no foreign key constraint', $column);
            }

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
        } elseif ($down !== null && trim((string) preg_replace('/\/\/.*$/m', '', Arr::first($down))) === '') {
            $flags[] = 'empty down()';
        }

        $up = Regex::groups('/function\s+up\s*\(\s*\)\s*(?::\s*void\s*)?\{(.*?)\n\s{4}\}/s', $code);
        $up = $up === null ? '' : Arr::first($up);

        if (preg_match('/->drop(Column|Columns)?\(|Schema::drop(IfExists)?\(/', $up) === 1) {
            $flags[] = 'drops data in up()';
        }

        if (preg_match('/\bApp\\\\Models\\\\|DB::(table|statement|update|insert|delete)\(/', $up) === 1) {
            $flags[] = 'changes data in up()';
        }

        return $flags;
    }
}
