<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final readonly class BrowserTestLocation
{
    /**
     * @param  'suite'|'path'  $type
     * @param  string  $value  suite name or directory
     * @param  string  $path  directory of the test files
     */
    public function __construct(
        public string $type,
        public string $value,
        public string $path,
    ) {}

    public function isSuite(): bool
    {
        return $this->type === 'suite';
    }
}
