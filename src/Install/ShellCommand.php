<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

/**
 * A command as the user would type it: `php` instead of the PHP binary,
 * arguments quoted only where the shell needs it.
 */
final class ShellCommand
{
    /** @param list<string> $command */
    public static function display(array $command): string
    {
        $parts = array_map(fn (string $part): string => $part === PHP_BINARY ? 'php' : $part, $command);

        return implode(' ', array_map(fn (string $part): string => preg_match('/^[\w@:.\/=+-]+$/', $part) === 1 ? $part : escapeshellarg($part), $parts));
    }
}
