<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Phpstan;

use Astro2006\LaravelAudit\Support\Read;

/**
 * An ignoreErrors entry of a PHPStan baseline (it has a count and a path).
 */
final readonly class BaselineEntry
{
    public function __construct(
        public ?string $identifier,
        public string $message,
        public int $count,
        public string $path,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (data_get($data, 'count') === null || data_get($data, 'path') === null) {
            return null;
        }

        $path = str_replace('\\', '/', Read::string($data, 'path'));

        return new self(
            Read::nullableString($data, 'identifier'),
            Read::string($data, 'message', Read::string($data, 'rawMessage')),
            max(1, Read::int($data, 'count', 1)),
            str_starts_with($path, './') ? substr($path, 2) : $path,
        );
    }
}
