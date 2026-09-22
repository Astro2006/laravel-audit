<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

final class ShellCommand
{
    /** @param list<string> $command */
    public static function display(array $command): string
    {
        $parts = array_map(fn (string $part): string => $part === PHP_BINARY ? 'php' : $part, $command);

        return implode(' ', array_map(fn (string $part): string => preg_match('/^[\w@:.\/=+-]+$/', $part) === 1 ? $part : escapeshellarg($part), $parts));
    }
}
