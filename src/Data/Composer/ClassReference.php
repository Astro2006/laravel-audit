<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Composer;

/**
 * References from one source file to one package.
 */
final readonly class ClassReference
{
    /** @param 'prod'|'test' $scope */
    public function __construct(
        public string $package,
        public string $scope,
        public string $file,
        public int $count,
    ) {}
}
