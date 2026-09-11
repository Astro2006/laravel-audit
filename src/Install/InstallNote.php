<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

/**
 * A hint in the install plan: a message key and its replacements.
 */
final readonly class InstallNote
{
    /** @param array<string, string> $replace */
    public function __construct(
        public string $key,
        public array $replace = [],
    ) {}
}
