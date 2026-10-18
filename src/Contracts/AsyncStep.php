<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;

interface AsyncStep extends Step
{
    public function start(PreflightContext $context): InvokedProcess|StepResult;

    public function finish(PreflightContext $context, ProcessResult $result): StepResult;
}
