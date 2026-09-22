<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final class ReportExtractor
{
    public function extract(string $answer): string
    {
        $answer = trim(str_replace(["\r\n", "\r"], "\n", $answer));

        $lines = explode("\n", $answer);

        $first = (string) reset($lines);
        $last = (string) end($lines);

        if (count($lines) >= 2
            && preg_match('/^(`{3,}|~{3,})\w*\s*$/', $first) === 1
            && preg_match('/^(`{3,}|~{3,})\s*$/', $last) === 1) {
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
