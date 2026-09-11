<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;

/**
 * One preflight step. Tool exit codes are data: a step only fails when it
 * produces no usable output.
 */
interface Step
{
    public function step(): PreflightStep;

    public function run(PreflightContext $context): StepResult;
}
