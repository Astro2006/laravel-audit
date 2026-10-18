<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Translations\MissingTranslations;
use Astro2006\LaravelAudit\Preflight\Data\Translations\TranslationReport;
use Illuminate\Support\Collection;

final class TranslationDiff
{
    private const int MAX_KEYS = 50;

    public function diff(string $langDir, string $defaultLocale): TranslationReport
    {
        if (! is_dir($langDir)) {
            return new TranslationReport(null, $defaultLocale, [], []);
        }

        /** @var Collection<string, list<string>> $keys locale => keys */
        $keys = new Collection;

        foreach (glob($langDir.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locale = basename($directory);

            if ($locale === 'vendor') {
                continue;
            }

            foreach (glob($directory.'/*.php') ?: [] as $file) {
                $keys->put($locale, [...$keys->get($locale, []), ...$this->flatten($this->load($file), basename($file, '.php'))]);
            }
        }

        foreach (glob($langDir.'/*.json') ?: [] as $file) {
            $values = json_decode((string) file_get_contents($file), true);
            $locale = basename($file, '.json');
            $jsonKeys = array_map(fn (int|string $key): string => 'json:'.$key, array_keys(is_array($values) ? $values : []));
            $keys->put($locale, [...$keys->get($locale, []), ...$jsonKeys]);
        }

        $locales = $keys->keys()->map(fn (int|string $locale): string => (string) $locale)->sort()->values()->all();
        $reference = $keys->get($defaultLocale, []);
        $missing = [];

        foreach ($locales as $locale) {
            if ($locale === $defaultLocale) {
                continue;
            }

            $absent = array_values(array_diff($reference, $keys->get($locale, [])));
            sort($absent);

            if ($absent !== []) {
                $missing[] = new MissingTranslations($locale, count($absent), array_slice($absent, 0, self::MAX_KEYS));
            }
        }

        return new TranslationReport($langDir, $defaultLocale, $locales, $missing);
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
