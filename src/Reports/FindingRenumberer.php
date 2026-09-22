<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Arr;

final class FindingRenumberer
{
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
                $line = (string) preg_replace_callback('/\b[A-Z]{2,4}-\d{3}\b/', fn (array $match): string => Arr::get($ids, Arr::first($match), Arr::first($match)), $line);
            }

            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }
}
