<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

/**
 * Markdown for detail files: labelled fields, lists and verbatim text.
 */
final class Details
{
    /**
     * Empty values are left out; lists become nested bullets.
     *
     * @param  array<string, mixed>  $fields  label => value
     */
    public static function fields(array $fields): string
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                $lines[] = sprintf('- **%s:**', $label);

                foreach ($value as $key => $item) {
                    $lines[] = '  - '.(is_string($key) ? $key.': ' : '').self::scalar($item);
                }

                continue;
            }

            $lines[] = sprintf('- **%s:** %s', $label, self::scalar($value));
        }

        return implode("\n", $lines);
    }

    /** @param  list<string>  $items */
    public static function list(array $items): string
    {
        return implode("\n", array_map(fn (string $item): string => '- '.$item, $items));
    }

    /** Verbatim text such as an error message, fenced so it is never read as structure. */
    public static function text(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : "````text\n".$text."\n````";
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', array_map(self::scalar(...), $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
