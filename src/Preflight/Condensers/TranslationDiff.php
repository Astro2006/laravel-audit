<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

/**
 * Translation keys that exist in the default locale but not in another one.
 */
final class TranslationDiff
{
    private const int MAX_KEYS = 50;

    /**
     * @return array{directory: string|null, default: string, locales: list<string>, missing: array<string, array{count: int, keys: list<string>}>}
     */
    public function diff(string $langDir, string $defaultLocale): array
    {
        if (! is_dir($langDir)) {
            return ['directory' => null, 'default' => $defaultLocale, 'locales' => [], 'missing' => []];
        }

        $keys = [];

        foreach (glob($langDir.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locale = basename($directory);

            if ($locale === 'vendor') {
                continue;
            }

            foreach (glob($directory.'/*.php') ?: [] as $file) {
                $values = $this->load($file);

                foreach ($this->flatten($values, basename($file, '.php')) as $key) {
                    $keys[$locale][$key] = true;
                }
            }
        }

        foreach (glob($langDir.'/*.json') ?: [] as $file) {
            $values = json_decode((string) file_get_contents($file), true);

            foreach (array_keys(is_array($values) ? $values : []) as $key) {
                $keys[basename($file, '.json')]['json:'.$key] = true;
            }
        }

        $locales = array_keys($keys);
        sort($locales);

        $missing = [];
        $reference = $keys[$defaultLocale] ?? [];

        foreach ($locales as $locale) {
            if ($locale === $defaultLocale) {
                continue;
            }

            $absent = array_keys(array_diff_key($reference, $keys[$locale]));
            sort($absent);

            if ($absent !== []) {
                $missing[$locale] = ['count' => count($absent), 'keys' => array_slice($absent, 0, self::MAX_KEYS)];
            }
        }

        return ['directory' => $langDir, 'default' => $defaultLocale, 'locales' => $locales, 'missing' => $missing];
    }

    /** @return array<mixed> */
    private function load(string $file): array
    {
        try {
            $values = (static fn (string $path) => include $path)($file);
        } catch (\Throwable) {
            return [];
        }

        return is_array($values) ? $values : [];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function flatten(array $values, string $prefix): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $full = $prefix.'.'.$key;

            if (is_array($value)) {
                array_push($keys, ...$this->flatten($value, $full));
            } else {
                $keys[] = $full;
            }
        }

        return $keys;
    }
}
