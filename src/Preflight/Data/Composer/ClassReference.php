<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Composer;

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
