<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use SimpleXMLElement;

/**
 * Clover coverage per class in app/, lowest coverage first.
 */
final class CoverageCondenser
{
    /**
     * @return array{lines_pct: float|null, files: int, classes: list<array<string, mixed>>}|null
     */
    public function condense(string $cloverPath, string $basePath, string $sourceDir = 'app'): ?array
    {
        if (! is_file($cloverPath)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($cloverPath);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $classes = [];
        $statements = 0;
        $covered = 0;

        foreach ($xml->xpath('//file') ?: [] as $file) {
            $path = str_replace('\\', '/', (string) ($file['name'] ?? ''));
            $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

            if (! str_starts_with($relative, rtrim($sourceDir, '/').'/')) {
                continue;
            }

            $metrics = $file->metrics[0] ?? null;
            $fileStatements = (int) ($metrics['statements'] ?? 0);
            $fileCovered = (int) ($metrics['coveredstatements'] ?? 0);
            $statements += $fileStatements;
            $covered += $fileCovered;

            $uncovered = [];

            foreach ($file->line as $line) {
                if ((string) ($line['type'] ?? '') === 'method' && (int) ($line['count'] ?? 0) === 0 && count($uncovered) < 10) {
                    $uncovered[] = (string) ($line['name'] ?? '');
                }
            }

            $className = null;

            if (isset($file->class[0])) {
                $name = (string) $file->class[0]['name'];
                $namespace = (string) ($file->class[0]['namespace'] ?? '');
                $className = str_contains($name, '\\') || $namespace === '' ? $name : $namespace.'\\'.$name;
            }

            $classes[] = [
                'class' => $className,
                'file' => $relative,
                'lines_pct' => $this->percent($fileCovered, $fileStatements),
                'statements' => $fileStatements,
                'uncovered_methods' => array_values(array_filter($uncovered)),
            ];
        }

        usort($classes, fn (array $a, array $b): int => [$a['lines_pct'], -$a['statements']] <=> [$b['lines_pct'], -$b['statements']]);

        return [
            'lines_pct' => $statements === 0 ? null : $this->percent($covered, $statements),
            'files' => count($classes),
            'classes' => $classes,
        ];
    }

    private function percent(int $covered, int $total): float
    {
        return $total === 0 ? 100.0 : round($covered / $total * 100, 1);
    }
}
