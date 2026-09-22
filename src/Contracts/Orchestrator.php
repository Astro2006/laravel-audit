<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\AuditExit;

/**
 * Runs one audit from start to end. The phases it calls (preflight, agents,
 * finalize) hold the rules; an orchestrator only decides what comes next.
 */
interface Orchestrator
{
    public function run(AuditOptions $options, AuditConsole $console): AuditExit;
}
