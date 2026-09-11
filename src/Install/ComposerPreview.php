<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

/**
 * Reads the output of `composer require … --dry-run` and lists changes to
 * packages the user did not ask for: upgrades, downgrades and removals.
 */
final class ComposerPreview
{
    /**
     * @param  list<string>  $requested
     * @return list<string> e.g. "symfony/process 7.3.0 → 7.3.4"
     */
    public function sideEffects(string $output, array $requested): array
    {
        $requested = array_map('strtolower', $requested);
        $effects = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/-\s+(Upgrading|Downgrading)\s+(\S+)\s+\((\S+)\s+=>\s+(\S+)\)/', $line, $match) === 1) {
                if (! in_array(strtolower($match[2]), $requested, true)) {
                    $effects[] = sprintf('%s %s → %s', $match[2], $match[3], $match[4]);
                }

                continue;
            }

            if (preg_match('/-\s+Removing\s+(\S+)\s+\((\S+)\)/', $line, $match) === 1 && ! in_array(strtolower($match[1]), $requested, true)) {
                $effects[] = sprintf('%s %s → removed', $match[1], $match[2]);
            }
        }

        return array_values(array_unique($effects));
    }
}
