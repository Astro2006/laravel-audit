<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final class Regex
{
    /**
     * The captured groups of the first match (without the full match), meant
     * for destructuring: [$id, $title] = Regex::groups(...). Unmatched groups
     * are empty strings. Returns null when the pattern does not match.
     *
     * @return list<string>|null
     */
    public static function groups(string $pattern, string $subject): ?array
    {
        if (preg_match($pattern, $subject, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return array_values(array_map(fn (?string $group): string => (string) $group, array_slice($match, 1)));
    }

    /**
     * Splits text into lines at \r\n, \r or \n. Byte-safe on purpose: \R
     * without /u also matches the byte 0x85, which is part of UTF-8
     * characters such as ✅, and /u fails on invalid UTF-8 in tool output.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return preg_split('/\r\n|\r|\n/', $text) ?: [];
    }

    /**
     * The first captured group of every match.
     *
     * @return list<string>
     */
    public static function all(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches);
        [, $group] = array_pad($matches, 2, []);

        return array_map('strval', $group);
    }
}
