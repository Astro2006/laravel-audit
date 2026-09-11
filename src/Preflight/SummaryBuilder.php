<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;

/**
 * Writes preflight/summary.json — the first file every agent reads.
 */
final class SummaryBuilder
{
    public function write(PreflightContext $context, string $runId, GateDecision $gate): PreflightSummary
    {
        $about = $context->result(PreflightStep::About);
        $stack = $context->result(PreflightStep::Stack);
        $loc = $context->result(PreflightStep::LinesOfCode);

        $steps = [];

        foreach (PreflightStep::cases() as $step) {
            $result = $context->result($step);

            if ($result !== null) {
                $steps[$step->value] = $result->toArray();
            }
        }

        $summary = [
            'run_id' => $runId,
            'generated_at' => date(DATE_ATOM),
            'audits' => $context->selection->auditValues(),
            'without' => $context->selection->withoutValues(),
            'app' => [
                'name' => $about?->get('name'),
                'laravel' => $about?->get('laravel'),
                'php' => $about?->get('php'),
                'env' => $about?->get('env'),
            ],
            'stack' => $stack === null ? [] : $stack->data,
            'loc' => $loc?->get('loc'),
            'gate' => $gate->value,
            'steps' => $steps,
        ];

        $context->writeJson('summary.json', $summary);

        return new PreflightSummary($summary, $context->preflightDir);
    }
}
