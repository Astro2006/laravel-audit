<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Skills;

use Astro2006\LaravelAudit\Support\Regex;

final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $path,
    ) {}

    public static function fromFile(string $path): ?self
    {
        [$frontmatter] = self::split((string) file_get_contents($path));
        $name = self::field($frontmatter, 'name');
        $description = self::field($frontmatter, 'description');

        return $name === '' || $description === '' ? null : new self($name, $description, $path);
    }

    /** @return string the first sentence of the description, at most $maxChars long */
    public function summary(int $maxChars): string
    {
        [$sentence] = Regex::groups('/^(.+?[.!?])(?:\s|$)/s', $this->description) ?? [$this->description];
        $sentence = (string) preg_replace('/\s+/', ' ', $sentence);

        return mb_strimwidth(trim($sentence), 0, $maxChars, '…');
    }

    /** @return string the instructions below the frontmatter, cut after $maxChars with a note */
    public function body(int $maxChars): string
    {
        [, $body] = self::split((string) file_get_contents($this->path));
        $body = trim($body);

        return mb_strlen($body) <= $maxChars ? $body : rtrim(mb_substr($body, 0, $maxChars))."\n\n(Skill shortened to {$maxChars} characters.)";
    }

    /** @return array{string, string} frontmatter, body */
    private static function split(string $contents): array
    {
        $parts = Regex::groups('/\A---\R(.*?)\R---\R?(.*)\z/s', $contents);

        if ($parts === null) {
            return ['', $contents];
        }

        [$frontmatter, $body] = $parts;

        return [$frontmatter, $body];
    }

    private static function field(string $frontmatter, string $key): string
    {
        $lines = Regex::lines($frontmatter);
        $value = null;
        $block = [];

        foreach ($lines as $line) {
            if ($value === null) {
                $match = Regex::groups('/^'.$key.':\s*(.*)$/', $line);

                if ($match !== null) {
                    [$value] = $match;
                }

                continue;
            }

            if (preg_match('/^\s+\S/', $line) !== 1) {
                break;
            }

            $block[] = trim($line);
        }

        if ($value === null) {
            return '';
        }

        $value = in_array(trim($value), ['>', '|', '>-', '|-', ''], true) ? implode(' ', $block) : trim($value);

        return trim($value, " \t\"'");
    }
}
