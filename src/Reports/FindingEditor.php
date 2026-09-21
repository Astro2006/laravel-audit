<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Support\Regex;

/**
 * Changes findings in a report body: removes rejected ones and lowers the
 * severity and confidence of downgraded ones. Everything else stays as the
 * agent wrote it.
 */
final class FindingEditor
{
    public function __construct(private readonly ReportParser $parser = new ReportParser) {}

    /** @param list<string> $ids */
    public function remove(string $body, array $ids): string
    {
        $report = $this->parser->parse($body);
        $drop = [];
        $removed = 0;

        foreach ($report->findings as $finding) {
            if (in_array($finding->id, $ids, true)) {
                $drop = [...$drop, ...range($finding->line, $finding->endLine)];
                $removed++;
            }
        }

        if ($drop === []) {
            return $body;
        }

        $lines = [];

        foreach (Regex::lines($body) as $index => $line) {
            if (! in_array($index + 1, $drop, true)) {
                $lines[] = $line;
            }
        }

        $result = implode("\n", $lines);

        return $removed === count($report->findings) ? $this->markEmpty($result) : $result;
    }

    /**
     * The findings as they appear in the body, each from its heading to its
     * closing `---`.
     *
     * @param  list<string>  $ids
     */
    public function blocks(string $body, array $ids): string
    {
        $lines = Regex::lines($body);
        $blocks = [];

        foreach ($this->parser->parse($body)->findings as $finding) {
            if (in_array($finding->id, $ids, true)) {
                $blocks[] = trim(implode("\n", array_slice($lines, $finding->line - 1, $finding->endLine - $finding->line + 1)));
            }
        }

        return implode("\n\n", $blocks);
    }

    /** @param list<string> $ids */
    public function downgrade(string $body, array $ids): string
    {
        $report = $this->parser->parse($body);
        $lines = Regex::lines($body);

        foreach ($report->findings as $finding) {
            if (! in_array($finding->id, $ids, true)) {
                continue;
            }

            for ($number = $finding->line; $number <= $finding->endLine; $number++) {
                $line = $lines[$number - 1];

                // The metadata table comes before the body; nothing below it is touched.
                if (preg_match('/^\*\*(Evidence|Impact|Recommendation)\*\*/i', trim($line)) === 1) {
                    break;
                }

                $lines[$number - 1] = (string) preg_replace_callback(
                    '/^(\|\s*(Severity|Confidence)\s*\|\s*)([a-z]+)(\s*\|\s*)$/i',
                    fn (array $match): string => $match[1].(strcasecmp($match[2], 'Severity') === 0
                        ? (Severity::tryFrom(strtolower($match[3]))?->lower()->value ?? $match[3])
                        : Confidence::Suspected->value).$match[4],
                    $line,
                );
            }
        }

        return implode("\n", $lines);
    }

    /** Puts "No findings." under `## Findings` once every finding is gone. */
    private function markEmpty(string $body): string
    {
        return (string) preg_replace('/^(##\s+Findings\s*)$/mi', "$1\n\nNo findings.", $body, 1);
    }
}
