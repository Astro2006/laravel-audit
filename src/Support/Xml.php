<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use SimpleXMLElement;

final class Xml
{
    public static function load(string $path): ?SimpleXMLElement
    {
        if (! is_file($path)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_use_internal_errors($previous);

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }

    public static function attr(SimpleXMLElement $node, string $name, string $default = ''): string
    {
        $value = $node->attributes()?->{$name};

        return $value === null || (string) $value === '' ? $default : (string) $value;
    }

    /** The first child element with this name, or null. */
    public static function child(SimpleXMLElement $node, string $name): ?SimpleXMLElement
    {
        foreach ($node->children() as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<SimpleXMLElement> */
    public static function xpath(SimpleXMLElement $node, string $expression): array
    {
        $result = $node->xpath($expression);

        return is_array($result) ? array_values($result) : [];
    }
}
