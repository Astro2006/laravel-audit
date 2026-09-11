<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final class Json
{
    /** @return array<mixed> */
    public static function readFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return self::decode((string) file_get_contents($path));
    }

    /**
     * Decodes JSON into an array; returns [] for anything else. Tolerates
     * noise before the JSON document, which some tools print.
     *
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            $start = strcspn($json, '{[');

            if ($start < strlen($json)) {
                $data = json_decode(substr($json, $start), true);
            }
        }

        return is_array($data) ? $data : [];
    }

    public static function writeFile(string $path, mixed $data): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}
