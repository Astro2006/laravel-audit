<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Writes a report file: the harness owns frontmatter and H1, the agent only
 * delivers the body.
 */
final class ReportWriter
{
    /** @param array<string, string|int|null> $frontmatter */
    public function write(string $path, array $frontmatter, string $title, string $body): void
    {
        $lines = ['---'];

        foreach ($frontmatter as $key => $value) {
            $lines[] = $key.': '.($value ?? '');
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# '.$title;
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines)."\n".rtrim($body)."\n");
    }
}
