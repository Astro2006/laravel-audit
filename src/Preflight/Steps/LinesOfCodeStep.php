<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;

/**
 * Non-blank lines in app/. Used to put finding counts in relation to size.
 */
final class LinesOfCodeStep implements Step
{
    public function step(): PreflightStep
    {
        return PreflightStep::LinesOfCode;
    }

    public function run(PreflightContext $context): StepResult
    {
        $files = $context->project->phpFiles('app');
        $lines = 0;

        foreach ($files as $file) {
            foreach (file($context->project->path($file)) ?: [] as $line) {
                if (trim($line) !== '') {
                    $lines++;
                }
            }
        }

        return StepResult::ok($this->step(), new LinesOfCodeDTO($lines, count($files)));
    }
}
