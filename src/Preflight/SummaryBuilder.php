<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\GateDecision;

/**
 * Writes preflight/summary.json — the first file every agent reads.
 */
final class SummaryBuilder
{
    public function write(PreflightContext $context, string $runId, GateDecision $gate): PreflightSummary
    {
        $results = $context->results;
        $about = $results->about();
        $steps = [];

        foreach ($results->all() as $result) {
            $steps = [...$steps, $result->step->value => $result->toArray()];
        }

        $context->writeJson('summary.json', [
            'run_id' => $runId,
            'generated_at' => date(DATE_ATOM),
            'audits' => $context->selection->auditValues(),
            'without' => $context->selection->withoutValues(),
            'app' => [
                'name' => $about?->name,
                'laravel' => $about?->laravel,
                'php' => $about?->php,
                'env' => $about?->env,
            ],
            'stack' => $results->stack()?->toArray() ?? [],
            'loc' => $results->linesOfCode()?->loc,
            'gate' => $gate->value,
            'steps' => $steps,
        ]);

        return new PreflightSummary($runId, $gate, $results, $context->preflightDir);
    }
}
