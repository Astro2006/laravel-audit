<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Tests\ClassCoverage;
use Astro2006\LaravelAudit\Preflight\Data\Tests\CoverageReport;
use Astro2006\LaravelAudit\Support\Xml;
use SimpleXMLElement;

final class CoverageCondenser
{
    public function condense(string $cloverPath, string $basePath, string $sourceDir = 'app'): ?CoverageReport
    {
        $xml = Xml::load($cloverPath);

        if ($xml === null) {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $prefix = rtrim($sourceDir, '/').'/';
        $classes = [];
        $statements = 0;
        $covered = 0;

        foreach (Xml::xpath($xml, '//file') as $file) {
            $path = str_replace('\\', '/', Xml::attr($file, 'name'));
            $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

            if (! str_starts_with($relative, $prefix)) {
                continue;
            }

            $metrics = Xml::child($file, 'metrics');
            $fileStatements = $metrics === null ? 0 : (int) Xml::attr($metrics, 'statements', '0');
            $fileCovered = $metrics === null ? 0 : (int) Xml::attr($metrics, 'coveredstatements', '0');
            $statements += $fileStatements;
            $covered += $fileCovered;

            $classes[] = new ClassCoverage(
                class: $this->className($file),
                file: $relative,
                linesPct: $this->percent($fileCovered, $fileStatements),
                statements: $fileStatements,
                uncoveredMethods: $this->uncoveredMethods($file),
            );
        }

        usort($classes, fn (ClassCoverage $a, ClassCoverage $b): int => [$a->linesPct, -$a->statements] <=> [$b->linesPct, -$b->statements]);

        return new CoverageReport($statements === 0 ? null : $this->percent($covered, $statements), $classes);
    }

    private function className(SimpleXMLElement $file): ?string
    {
        $class = Xml::child($file, 'class');

        if ($class === null) {
            return null;
        }

        $name = Xml::attr($class, 'name');
        $namespace = Xml::attr($class, 'namespace');

        return str_contains($name, '\\') || $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /** @return list<string> at most ten */
    private function uncoveredMethods(SimpleXMLElement $file): array
    {
        $methods = [];

        foreach ($file->children() as $line) {
            if ($line->getName() === 'line'
                && Xml::attr($line, 'type') === 'method'
                && (int) Xml::attr($line, 'count', '0') === 0
                && Xml::attr($line, 'name') !== '') {
                $methods[] = Xml::attr($line, 'name');
            }
        }

        return array_slice($methods, 0, 10);
    }

    private function percent(int $covered, int $total): float
    {
        return $total === 0 ? 100.0 : round($covered / $total * 100, 1);
    }
}
