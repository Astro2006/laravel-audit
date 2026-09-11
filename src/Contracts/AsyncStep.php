<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * A step that runs in the background while the light steps run.
 */
interface AsyncStep extends Step
{
    /**
     * Starts the process, or returns a final result right away (e.g. skipped).
     */
    public function start(PreflightContext $context): InvokedProcess|StepResult;

    public function finish(PreflightContext $context, ProcessResult $result): StepResult;
}
