<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Arr;

final class FindingRenumberer
{
    public const int MAX_TITLE = 120;

    private const string ID = '/\b[A-Z]{2,4}-\d{3}\b/';

    /**
     * Gives the findings of one task their audit-wide IDs and remaps
     * references to them in the Related field.
     *
     * @param  array<string, string>  $ids  task ID => audit ID
     */
    public static function renumber(string $findings, array $ids): string
    {
        $lines = [];

        foreach (Regex::lines($findings) as $line) {
            if (strcasecmp(trim($line), 'No findings.') === 0) {
                continue;
            }

            $heading = Regex::groups('/^(###\s+)([A-Z]{2,4}-\d{3})(\s*[—–-].*)$/u', $line);

            if ($heading !== null) {
                [$hashes, $id, $rest] = $heading;
                $line = $hashes.Arr::get($ids, $id, $id).$rest;
            } elseif (preg_match('/^\|\s*Related\s*\|/i', $line) === 1) {
                $line = self::remap($line, $ids);
            }

            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Replaces the task-local IDs in free text (limitations, sections) with
     * the audit-wide ones.
     *
     * @param  array<string, string>  $ids  task ID => audit ID
     */
    public static function remap(string $text, array $ids): string
    {
        return $ids === [] ? $text : (string) preg_replace_callback(self::ID, fn (array $match): string => Arr::get($ids, Arr::first($match), Arr::first($match)), $text);
    }

    /** @return string the finding block with its title cut at a word boundary when it is longer than MAX_TITLE */
    public static function trimTitle(string $block): string
    {
        return (string) preg_replace_callback('/^(###\s+[A-Z]{2,4}-\d{3}\s*[—–-]\s*)(.+)$/mu', function (array $match): string {
            $title = trim((string) Arr::get($match, 2));

            if (mb_strlen($title) <= self::MAX_TITLE) {
                return (string) Arr::get($match, 0);
            }

            $cut = mb_substr($title, 0, self::MAX_TITLE - 1);
            $space = mb_strrpos($cut, ' ');

            return Arr::get($match, 1).rtrim($space === false ? $cut : mb_substr($cut, 0, $space)).'…';
        }, $block, 1);
    }
}
