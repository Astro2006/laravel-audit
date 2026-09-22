<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Illuminate\Support\Collection;
use SimpleXMLElement;

/**
 * The project's PHPUnit config (phpunit.xml and its .dist variants).
 */
final class PhpunitConfig
{
    private SimpleXMLElement|false|null $xml = null;

    public function __construct(private readonly ProjectInspector $project) {}

    public function path(): ?string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $file) {
            if (is_file($this->project->path($file))) {
                return $this->project->path($file);
            }
        }

        return null;
    }

    /**
     * Test suite names.
     *
     * @return list<string>
     */
    public function testSuites(): array
    {
        $xml = $this->xml();

        if ($xml === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (SimpleXMLElement $suite): string => Xml::attr($suite, 'name'),
            Xml::xpath($xml, '//testsuite'),
        )));
    }

    /** The first directory of a test suite, relative to the project. */
    public function suiteDirectory(string $suite): ?string
    {
        $xml = $this->xml();

        foreach ($xml === null ? [] : Xml::xpath($xml, '//testsuite') as $node) {
            $directory = Xml::child($node, 'directory');

            if (Xml::attr($node, 'name') === $suite && $directory !== null) {
                return trim((string) $directory, './');
            }
        }

        return null;
    }

    /**
     * <env> and <server> values.
     *
     * @return array<string, string>
     */
    public function env(bool $forcedOnly = false): array
    {
        $xml = $this->xml();

        if ($xml === null) {
            return [];
        }

        return (new Collection(Xml::xpath($xml, '//php/env | //php/server')))
            ->filter(fn (SimpleXMLElement $node): bool => Xml::attr($node, 'name') !== '')
            ->filter(fn (SimpleXMLElement $node): bool => ! $forcedOnly || in_array(strtolower(Xml::attr($node, 'force')), ['true', '1'], true))
            ->mapWithKeys(fn (SimpleXMLElement $node): array => [Xml::attr($node, 'name') => Xml::attr($node, 'value')])
            ->all();
    }

    private function xml(): ?SimpleXMLElement
    {
        if ($this->xml === null) {
            $path = $this->path();
            $this->xml = ($path === null ? null : Xml::load($path)) ?? false;
        }

        return $this->xml ?: null;
    }
}
