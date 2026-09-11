<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

use Astro2006\LaravelAudit\Support\Regex;

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

        foreach (Regex::lines($output) as $line) {
            $change = Regex::groups('/-\s+(?:Upgrading|Downgrading)\s+(\S+)\s+\((\S+)\s+=>\s+(\S+)\)/', $line);

            if ($change !== null) {
                [$package, $from, $to] = $change;

                if (! in_array(strtolower($package), $requested, true)) {
                    $effects[] = sprintf('%s %s → %s', $package, $from, $to);
                }

                continue;
            }

            $removal = Regex::groups('/-\s+Removing\s+(\S+)\s+\((\S+)\)/', $line);

            if ($removal !== null) {
                [$package, $version] = $removal;

                if (! in_array(strtolower($package), $requested, true)) {
                    $effects[] = sprintf('%s %s → removed', $package, $version);
                }
            }
        }

        return array_values(array_unique($effects));
    }
}
