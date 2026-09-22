<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data;

/**
 * What `php artisan audit` was asked to do, read from its options.
 */
final readonly class AuditOptions
{
    /**
     * @param  list<string>  $audits  audits named with --tests, --frontend, …; empty = the configured ones
     * @param  list<string>  $without  audits and steps left out with --without
     * @param  bool  $force  continue past the test gate without asking
     * @param  string|null  $reuseRunId  run whose preflight to reuse; null = the latest
     */
    public function __construct(
        public array $audits = [],
        public array $without = [],
        public bool $force = false,
        public bool $preflightOnly = false,
        public bool $reusePreflight = false,
        public ?string $reuseRunId = null,
        public ?string $agent = null,
        public ?string $model = null,
        public ?string $mode = null,
    ) {}
}
