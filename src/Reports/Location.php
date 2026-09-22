<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Support\Regex;
use Stringable;

final readonly class Location implements Stringable
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?int $endLine = null,
        public ?string $route = null,
        public ?string $package = null,
        public ?string $missing = null,
    ) {}

    /**
     * Parses a "Locations" table cell. Items are expected in backticks and
     * separated by commas; unquoted items are accepted as a fallback.
     *
     * @return list<self>
     */
    public static function parseList(string $value): array
    {
        $quoted = Regex::all('/`([^`]+)`/', $value);
        $items = $quoted !== [] ? $quoted : explode(',', $value);

        $locations = [];

        foreach ($items as $item) {
            $location = self::parse($item);

            if ($location !== null) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    public static function parse(string $raw): ?self
    {
        $raw = trim($raw, " \t`");

        if ($raw === '') {
            return null;
        }

        $route = Regex::groups('/^route:\s*(.+)$/i', $raw);

        if ($route !== null) {
            [$value] = $route;

            return new self(route: self::normalizeRoute($value));
        }

        $package = Regex::groups('/^package:\s*(\S+)$/i', $raw);

        if ($package !== null) {
            [$value] = $package;

            return new self(package: strtolower($value));
        }

        $missing = Regex::groups('/^missing:\s*(\S+)$/i', $raw);

        if ($missing !== null) {
            [$value] = $missing;

            return new self(missing: self::normalizePath($value));
        }

        $lines = Regex::groups('/^(.+?):(\d+)(?:\s*-\s*(\d+))?$/', $raw);

        if ($lines !== null) {
            [$file, $line, $endLine] = $lines;

            return new self(
                file: self::normalizePath($file),
                line: (int) $line,
                endLine: $endLine === '' ? null : (int) $endLine,
            );
        }

        return new self(file: self::normalizePath($raw));
    }

    public function target(): string
    {
        return match (true) {
            $this->route !== null => 'route:'.$this->route,
            $this->package !== null => 'package:'.$this->package,
            $this->missing !== null => 'missing:'.$this->missing,
            default => (string) $this->file,
        };
    }

    public function isFile(): bool
    {
        return $this->file !== null;
    }

    public function isMissing(): bool
    {
        return $this->missing !== null;
    }

    public function lastLine(): ?int
    {
        return $this->endLine ?? $this->line;
    }

    public function overlaps(self $other): bool
    {
        if ($this->target() !== $other->target()) {
            return false;
        }

        if (! $this->isFile() || $this->line === null || $other->line === null) {
            return true;
        }

        return $this->line <= $other->lastLine() && $other->line <= $this->lastLine();
    }

    public function __toString(): string
    {
        if (! $this->isFile()) {
            return $this->target();
        }

        return match (true) {
            $this->line === null => (string) $this->file,
            $this->endLine === null => $this->file.':'.$this->line,
            default => $this->file.':'.$this->line.'-'.$this->endLine,
        };
    }

    private static function normalizeRoute(string $route): string
    {
        $route = (string) preg_replace('/\s+/', ' ', trim($route));
        $parts = Regex::groups('/^([A-Za-z|]+)\s+(.+)$/', $route);

        if ($parts === null) {
            return $route;
        }

        [$methods, $uri] = $parts;

        return strtoupper($methods).' '.$uri;
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }
}
