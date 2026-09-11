<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Data\Phpstan\BaselineEntry;
use Astro2006\LaravelAudit\Support\Read;
use Nette\Neon\Neon;

/**
 * Reads the project's PHPStan config: level (as a fallback when
 * dump-parameters is not available) and baseline entries.
 */
final class PhpstanConfig
{
    public function __construct(private readonly string $configPath) {}

    /** Level from the config and its includes; the main config comes first. */
    public function level(): ?string
    {
        foreach ($this->files() as $data) {
            $level = Read::nullableString($data, 'parameters.level');

            if ($level !== null) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Baseline-style ignoreErrors entries (with count and path) from the
     * config and its includes.
     *
     * @return list<BaselineEntry>
     */
    public function baselineEntries(): array
    {
        $entries = [];

        foreach ($this->files() as $data) {
            foreach (Read::arrays($data, 'parameters.ignoreErrors') as $entry) {
                $baseline = BaselineEntry::fromArray($entry);

                if ($baseline !== null) {
                    $entries[] = $baseline;
                }
            }
        }

        return $entries;
    }

    /**
     * The main config first, then its includes (breadth first). Files in
     * vendor/ and paths with PHPStan variables are skipped.
     *
     * @return list<array<mixed>>
     */
    private function files(): array
    {
        $files = [];
        $seen = [];
        $queue = [$this->configPath];

        while ($queue !== []) {
            $real = realpath((string) array_shift($queue));

            if ($real === false || in_array($real, $seen, true)) {
                continue;
            }

            $seen[] = $real;
            $data = $this->load($real);
            $files[] = $data;

            foreach (Read::strings($data, 'includes') as $include) {
                if (str_contains($include, '%') || str_contains($include, '/vendor/') || str_starts_with($include, 'vendor/')) {
                    continue;
                }

                $queue[] = str_starts_with($include, '/') ? $include : dirname($real).'/'.$include;
            }
        }

        return $files;
    }

    /** @return array<mixed> */
    private function load(string $path): array
    {
        try {
            $data = str_ends_with($path, '.php')
                ? (static fn (string $file) => include $file)($path)
                : Neon::decodeFile($path);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
