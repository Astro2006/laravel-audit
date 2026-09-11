<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Cuts the report body out of the agent's final answer: drops a surrounding
 * code fence and anything before the first level-2 heading (introductions,
 * a frontmatter or an H1 the agent was told not to write).
 */
final class ReportExtractor
{
    public function extract(string $answer): string
    {
        $answer = trim(str_replace(["\r\n", "\r"], "\n", $answer));

        $lines = explode("\n", $answer);

        if (count($lines) >= 2
            && preg_match('/^(`{3,}|~{3,})\w*\s*$/', $lines[0]) === 1
            && preg_match('/^(`{3,}|~{3,})\s*$/', (string) end($lines)) === 1) {
            $lines = array_slice($lines, 1, -1);
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/^##\s+(?!#)/', $line) === 1) {
                return trim(implode("\n", array_slice($lines, $index)))."\n";
            }
        }

        return trim(implode("\n", $lines))."\n";
    }
}
