<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

/**
 * Lenient, typed reads from foreign data (tool JSON, config, decoded files).
 * Missing keys and wrong types fall back to the default instead of failing.
 * Keys use dot notation, like data_get().
 */
final class Read
{
    /** @param array<mixed>|object $data */
    public static function string(array|object $data, string $key, string $default = ''): string
    {
        $value = data_get($data, $key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<mixed>|object $data */
    public static function nullableString(array|object $data, string $key): ?string
    {
        $value = data_get($data, $key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @param array<mixed>|object $data */
    public static function int(array|object $data, string $key, int $default = 0): int
    {
        $value = data_get($data, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<mixed>|object $data */
    public static function nullableInt(array|object $data, string $key): ?int
    {
        $value = data_get($data, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<mixed>|object $data */
    public static function float(array|object $data, string $key, float $default = 0.0): float
    {
        $value = data_get($data, $key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<mixed>|object $data */
    public static function nullableFloat(array|object $data, string $key): ?float
    {
        $value = data_get($data, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<mixed>|object $data */
    public static function bool(array|object $data, string $key, bool $default = false): bool
    {
        $value = data_get($data, $key);

        return is_bool($value) ? $value : (is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $default);
    }

    /**
     * @param  array<mixed>|object  $data
     * @return array<mixed>
     */
    public static function array(array|object $data, string $key): array
    {
        $value = data_get($data, $key);

        return is_array($value) ? $value : [];
    }

    /**
     * Only the entries that are arrays themselves (e.g. a list of objects).
     *
     * @param  array<mixed>|object  $data
     * @return list<array<mixed>>
     */
    public static function arrays(array|object $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key), 'is_array'));
    }

    /**
     * @param  array<mixed>|object  $data
     * @return list<string>
     */
    public static function strings(array|object $data, string $key): array
    {
        return array_values(array_map('strval', array_filter(self::array($data, $key), 'is_scalar')));
    }

    /**
     * @param  array<mixed>|object  $data
     * @return array<string, string>
     */
    public static function stringMap(array|object $data, string $key): array
    {
        $map = [];

        foreach (self::array($data, $key) as $name => $value) {
            if (is_scalar($value)) {
                $map = [...$map, (string) $name => (string) $value];
            }
        }

        return $map;
    }

    /**
     * Keys of an associative array, as strings.
     *
     * @param  array<mixed>|object  $data
     * @return list<string>
     */
    public static function keys(array|object $data, string $key): array
    {
        return array_map('strval', array_keys(self::array($data, $key)));
    }
}
