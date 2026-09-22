<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Phases;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Orchestration\RunContext;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\PreflightRunner;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Preflight\SummaryBuilder;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;

/**
 * Runs every preflight step the selected audits need, or copies the
 * preflight of an earlier run (--reuse-preflight), then asks the test gate
 * once every step has a result.
 */
final class Preflight
{
    public function __construct(
        private readonly AuditConfig $config,
        private readonly ApplyTestGate $applyTestGate,
    ) {}

    /** @return PreflightSummary|null null when there is no usable preflight */
    public function handle(RunContext $context): ?PreflightSummary
    {
        $context->console->section('preflight');
        $summary = $context->options->reusePreflight ? $this->reuse($context) : $this->fresh($context);

        if ($summary !== null) {
            $context->manifest->gate = $summary->gate;
            $context->manifest->browser = self::browserLabel($summary);
        }

        return $summary;
    }

    private function fresh(RunContext $context): PreflightSummary
    {
        $plan = $context->plan;
        $run = $context->run;
        $preflight = new PreflightContext($plan->project, $run->preflightDir(), $run->logsDir(), $this->config, $plan->selection, ChildEnvironment::forApp($plan->project));

        PreflightRunner::standard($plan->guard, (string) config('app.locale', 'en'))->run(
            $preflight,
            $context->console->stepFinished(...),
            $context->console->stepStarted(...),
        );
        $context->console->live->release();

        $context->manifest->steps = $preflight->results;
        $context->manifest->preflightComplete = ! $preflight->results->hasErrors();

        return (new SummaryBuilder)->write($preflight, $run->id, $this->applyTestGate->handle($context, $preflight->results));
    }

    private function reuse(RunContext $context): ?PreflightSummary
    {
        $steps = $this->copyEarlierPreflight($context);
        $context->console->live->release();

        if ($steps === null) {
            return null;
        }

        $context->manifest->steps = $steps;
        $context->manifest->preflightComplete = true;

        return new PreflightSummary($context->run->id, $this->applyTestGate->handle($context, $steps), $steps, $context->run->preflightDir());
    }

    /** @return StepResults|null null when there is no usable preflight */
    private function copyEarlierPreflight(RunContext $context): ?StepResults
    {
        $run = $context->run;
        $console = $context->console;
        $messages = $console->messages;
        $required = $context->plan->selection->requiredSteps();
        $reuse = new PreflightReuse;
        $source = $reuse->find($run->outputRoot, $context->options->reuseRunId, $run->id);

        if ($source === null) {
            $console->error($messages->get('reuse.not_found', ['path' => $context->plan->project->relative($run->outputRoot)]));

            return null;
        }

        $reuse->copy($run->outputRoot.'/'.$source->runId.'/preflight', $run->preflightDir());
        $stored = PreflightSummary::fromDirectory($run->preflightDir());
        $missing = $reuse->missingSteps($stored, $required);

        if ($missing !== []) {
            $console->error($messages->get('reuse.incomplete', ['run' => $source->runId, 'steps' => implode(', ', $missing)]));

            return null;
        }

        $console->line('  '.$messages->get('reuse.using', ['run' => $source->runId]));

        $git = $context->manifest->git;
        $now = ($git->commit ?? '').($git->dirty ? '+dirty' : '');

        if ($source->codeState() !== $now || str_contains($now, '+dirty')) {
            $console->line('  <fg=yellow>⚠</> '.$messages->get('reuse.commit_changed', ['then' => $source->codeState() ?: '?', 'now' => $now ?: '?']));
        }

        foreach ($stored->steps->all() as $result) {
            if (in_array($result->step, $required, true)) {
                $console->stepFinished($result);
            }
        }

        $context->manifest->preflightFrom = $source->runId;

        return $stored->steps;
    }

    private static function browserLabel(PreflightSummary $summary): string
    {
        $result = $summary->steps->get(PreflightStep::Browser);

        return match ($result?->status) {
            StepStatus::Ok => 'playwright',
            StepStatus::Skipped => 'skipped ('.$result->reason?->value.')',
            StepStatus::Error => 'error',
            null => 'not part of this run',
        };
    }
}
