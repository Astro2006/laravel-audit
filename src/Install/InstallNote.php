<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

final readonly class InstallNote
{
    /** @param array<string, string> $replace */
    public function __construct(
        public string $key,
        public array $replace = [],
    ) {}
}
