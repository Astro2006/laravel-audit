<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final class MarkdownTable
{
    /**
     * Header, separator and one line per row. Cells are escaped.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     * @return list<string>
     */
    public static function lines(array $headers, array $rows): array
    {
        $lines = ['| '.implode(' | ', $headers).' |', '|'.str_repeat('---|', count($headers))];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(fn (string|int|float $cell): string => self::cell((string) $cell), $row)).' |';
        }

        return $lines;
    }

    /**
     * A level-2 section with one table; nothing when there are no rows.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     * @return list<string>
     */
    public static function section(string $title, array $headers, array $rows, bool $always = false, ?string $intro = null): array
    {
        if ($rows === [] && ! $always) {
            return [];
        }

        return ['## '.$title, '', ...($intro === null ? [] : [$intro, '']), ...self::lines($headers, $rows), ''];
    }

    public static function cell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], trim($value));
    }
}
