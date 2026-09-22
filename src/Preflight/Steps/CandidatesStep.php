<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\CandidatesDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\Condensers\CodeCandidates;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;

/**
 * Searches the code once for risky patterns, so the agents do not have to.
 */
final class CandidatesStep implements Step
{
    public function __construct(private readonly CodeCandidates $candidates = new CodeCandidates) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Candidates;
    }

    public function run(PreflightContext $context): StepResult
    {
        $patterns = $this->candidates->find($context->project);
        $context->writeJson('code/candidates.json', ['patterns' => $patterns]);

        $found = array_filter($patterns, fn (array $pattern): bool => $pattern['total'] > 0);

        return StepResult::ok($this->step(), new CandidatesDTO(count($found), array_sum(array_column($patterns, 'total'))));
    }
}
