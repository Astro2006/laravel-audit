<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Illuminate\Support\Arr;

final class EvidenceChecker
{
    private const int MIN_LINE_LENGTH = 8;

    private const float MIN_SHARE = 0.5;

    private const array OUTPUT_LANGUAGES = ['text', 'txt', 'plaintext', 'console', 'shell', 'bash', 'sh', 'zsh', 'log', 'output', 'diff'];

    /**
     * True when the evidence matches, false when it does not, null when there
     * is nothing to compare (no code block, tool output, only short lines).
     *
     * @param  list<string>  $contents  the files the finding points at
     */
    public function matches(string $evidence, array $contents): ?bool
    {
        if ($contents === [] || stripos($evidence, 'preflight') !== false) {
            return null;
        }

        $lines = $this->significantLines($evidence);

        if ($lines === []) {
            return null;
        }

        $haystack = implode('', array_map($this->normalize(...), preg_split('/\R/', implode("\n", $contents)) ?: []));
        $found = count(array_filter($lines, fn (string $line): bool => str_contains($haystack, $line)));

        return $found / count($lines) >= self::MIN_SHARE;
    }

    /** @return list<string> */
    private function significantLines(string $evidence): array
    {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*([\w+-]*)[^\n]*\n(.*?)^\s{0,3}\1\s*$/ms', $evidence, $match) !== 1) {
            return [];
        }

        if (in_array(strtolower(Arr::get($match, 2)), self::OUTPUT_LANGUAGES, true)) {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\R/', Arr::get($match, 3)) ?: [] as $line) {
            $line = $this->normalize((string) preg_replace('/^\s*\d+\s*[:|]\s/', '', $line));

            if (mb_strlen($line) < self::MIN_LINE_LENGTH || $this->isFiller($line)) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function isFiller(string $line): bool
    {
        return preg_match('~^(//|#|/\*|\*|<!--|\{\{--)~', $line) === 1
            || preg_match('/^[.…]*$/u', $line) === 1;
    }

    private function normalize(string $line): string
    {
        return (string) preg_replace('/\s+/u', '', $line);
    }
}
