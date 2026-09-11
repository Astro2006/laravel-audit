<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Nette\Neon\Neon;

/**
 * Reads the project's PHPStan config: level (as a fallback when
 * dump-parameters is not available) and baseline entries.
 */
final class PhpstanConfig
{
    public function __construct(private readonly string $configPath) {}

    /** Level from the config and its includes; later files cannot override the main config. */
    public function level(): ?string
    {
        foreach ($this->files() as $data) {
            $level = $data['parameters']['level'] ?? null;

            if ($level !== null && $level !== '') {
                return (string) $level;
            }
        }

        return null;
    }

    /**
     * Baseline-style ignoreErrors entries (with count and path) from the
     * config and its includes.
     *
     * @return list<array{identifier: string|null, message: string, count: int, path: string}>
     */
    public function baselineEntries(): array
    {
        $entries = [];

        foreach ($this->files() as $file => $data) {
            $dir = dirname($file);

            foreach ((array) ($data['parameters']['ignoreErrors'] ?? []) as $entry) {
                if (! is_array($entry) || ! isset($entry['count']) || ! isset($entry['path'])) {
                    continue;
                }

                $entries[] = [
                    'identifier' => isset($entry['identifier']) ? (string) $entry['identifier'] : null,
                    'message' => (string) ($entry['message'] ?? $entry['rawMessage'] ?? ''),
                    'count' => (int) $entry['count'],
                    'path' => $this->relativeTo($dir, (string) $entry['path']),
                ];
            }
        }

        return $entries;
    }

    /**
     * The main config first, then its includes (depth first). Files in
     * vendor/ and paths with PHPStan variables are skipped.
     *
     * @return array<string, array<mixed>>
     */
    private function files(): array
    {
        $files = [];
        $queue = [$this->configPath];

        while ($queue !== []) {
            $file = array_shift($queue);
            $real = realpath($file);

            if ($real === false || isset($files[$real])) {
                continue;
            }

            $data = $this->load($real);
            $files[$real] = $data;

            foreach ((array) ($data['includes'] ?? []) as $include) {
                $include = (string) $include;

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

    private function relativeTo(string $dir, string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, '/') ? $path : ltrim((string) preg_replace('#(^|/)\./#', '$1', $path), '/');
    }
}
