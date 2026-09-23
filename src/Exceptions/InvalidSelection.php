<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Exceptions;

use InvalidArgumentException;

final class InvalidSelection extends InvalidArgumentException
{
    /** @param list<string> $allowed */
    public static function unknownWithout(string $value, array $allowed): self
    {
        return new self(sprintf('Unknown --without value "%s". Allowed: %s.', $value, implode(', ', $allowed)));
    }

    /** @param list<string> $allowed */
    public static function unknownStep(string $audit, string $value, array $allowed): self
    {
        return new self(sprintf('Unknown preflight step "%s" in audit.audits.%s.preflight. Allowed: %s.', $value, $audit, implode(', ', $allowed)));
    }

    public static function selectedAndExcluded(string $audit): self
    {
        return new self(sprintf('The audit "%s" is selected and excluded at the same time.', $audit));
    }

    public static function invalidConcurrency(string $value): self
    {
        return new self(sprintf('--concurrency must be a whole number of at least 1, "%s" given.', $value));
    }

    public static function nothingLeft(): self
    {
        return new self('No audit left to run.');
    }
}
