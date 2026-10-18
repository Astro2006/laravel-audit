<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Support\Regex;

final class LimitSignals
{
    private const string PATTERN = '/usage limit|rate[ _-]?limit|too many requests|overloaded|credit balance|\b429\b|quota/i';

    /** @param  string|null  $text  what the agent CLI said when it failed */
    public static function matches(?string $text): bool
    {
        return $text !== null && preg_match(self::PATTERN, $text) === 1;
    }

    /**
     * @param  string|null  $text  what the agent CLI said when it failed
     * @return int|null the unix time the limit resets, when the message names it
     */
    public static function resetsAt(?string $text): ?int
    {
        if ($text === null) {
            return null;
        }

        $epoch = Regex::groups('/\|(\d{10})\b/', $text);

        if ($epoch !== null) {
            [$seconds] = $epoch;

            return (int) $seconds;
        }

        $phrase = Regex::groups('/resets?(?:\s+at|\s+on|\s+in)?\s+([^.()\n]+)/i', $text);

        if ($phrase === null) {
            return null;
        }

        [$when] = $phrase;
        $time = strtotime(trim($when));

        return $time === false ? null : $time;
    }
}
