<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Illuminate\Support\Arr;

final class EvidenceChecker
{
    private const int MIN_LINE_LENGTH = 8;

    private const float MIN_SHARE = 0.66;

    private const array OUTPUT_LANGUAGES = ['text', 'txt', 'plaintext', 'console', 'shell', 'bash', 'sh', 'zsh', 'log', 'output', 'diff'];

    private const string BOILERPLATE = '/^(<\?php|namespace\s|use\s[\w\\\\{}, ]+;$|((abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+\w+|declare\s*\(|return\s+new\s+class\b|[{}()\[\];,]+$)/';

    /**
     * True when the evidence matches, false when it does not, null when there
     * is nothing to compare (a preflight reference, tool output, only short lines).
     * Boilerplate such as namespace, use and class lines only counts when
     * nothing else is quoted, because an invented method inside a real class
     * would pass otherwise.
     *
     * @param  list<string>  $contents  the files the finding points at
     */
    public function matches(string $evidence, array $contents): ?bool
    {
        $code = self::code($evidence);

        if ($contents === [] || $code === null || self::isPreflightReference($evidence)) {
            return null;
        }

        $lines = $this->significantLines($code);
        $specific = array_values(array_filter($lines, fn (string $line): bool => preg_match(self::BOILERPLATE, $line) !== 1));
        $compared = $specific === [] ? $lines : $specific;

        if ($compared === []) {
            return null;
        }

        $haystack = implode('', array_map($this->normalize(...), preg_split('/\R/', implode("\n", $contents)) ?: []));
        $found = count(array_filter($compared, fn (string $line): bool => str_contains($haystack, $this->normalize($line))));

        return $found / count($compared) >= self::MIN_SHARE;
    }

    /** @return bool true for evidence that points at preflight data (`preflight: T-01`) instead of quoting code */
    public static function isPreflightReference(string $evidence): bool
    {
        return preg_match('/^\s*preflight:/i', $evidence) === 1;
    }

    /**
     * @return string|null the quoted code of a fenced block, also one the model forgot to close,
     *                     of a `<code>` or `<pre>` element, or of inline code that looks like code;
     *                     null for tool output and prose
     */
    public static function code(string $evidence): ?string
    {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*([\w+-]*)[^\n]*\n(.*?)(?:^\s{0,3}\1\s*$|\z)/ms', $evidence, $match) === 1) {
            return in_array(strtolower((string) Arr::get($match, 2)), self::OUTPUT_LANGUAGES, true) ? null : (string) Arr::get($match, 3);
        }

        if (preg_match('~<(code|pre)[^>]*>(.*?)(?:</\1>|\z)~s', $evidence, $match) === 1) {
            return html_entity_decode((string) Arr::get($match, 2));
        }

        preg_match_all('/`([^`\n]{8,})`/', $evidence, $inline);
        $spans = array_values(array_filter((array) Arr::get($inline, 1), fn (string $span): bool => preg_match('/[;()$>]/', $span) === 1));

        return $spans === [] ? null : implode("\n", $spans);
    }

    /** @return list<string> trimmed lines long enough to mean something, without line numbers and filler */
    private function significantLines(string $code): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*\d+\s*[:|]\s/', '', $line));

            if (mb_strlen($this->normalize($line)) < self::MIN_LINE_LENGTH || $this->isFiller($line)) {
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
