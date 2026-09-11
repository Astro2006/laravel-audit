<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Contracts\AsyncStep;
use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs the preflight in two strands: the heavy steps (tests → gate →
 * browser → phpstan) one after another, the light steps meanwhile. Tests
 * start in the background first, so the light steps run while they do.
 */
final class PreflightRunner
{
    /** @var Collection<string, Step> */
    private Collection $steps;

    /** @param iterable<Step> $steps */
    public function __construct(iterable $steps)
    {
        $this->steps = (new Collection($steps))->keyBy(fn (Step $step): string => $step->step()->value);
    }

    /**
     * @param  Closure(StepResult): void  $onResult  called after every step
     * @param  Closure(StepResult): GateDecision  $gate  called with the test result
     * @param  (Closure(PreflightStep): void)|null  $onStart
     */
    public function run(PreflightContext $context, Closure $onResult, Closure $gate, ?Closure $onStart = null): GateDecision
    {
        $required = $context->selection->requiredSteps();
        $has = fn (PreflightStep $step): bool => in_array($step, $required, true) && $this->steps->has($step->value);

        $testsRunning = null;
        $testsStarted = 0.0;

        $tests = $this->step(PreflightStep::Tests);

        if ($has(PreflightStep::Tests) && $tests !== null) {
            $onStart?->__invoke(PreflightStep::Tests);
            $testsStarted = microtime(true);

            if ($tests instanceof AsyncStep) {
                $started = $this->attempt(PreflightStep::Tests, fn () => $tests->start($context));

                if ($started instanceof InvokedProcess) {
                    $testsRunning = $started;
                } else {
                    $this->record($context, $started->withDuration(microtime(true) - $testsStarted), $onResult);
                }
            } else {
                $this->runStep($context, $tests, $onResult);
            }
        }

        foreach ($required as $step) {
            if ($step->isHeavy() || ! $has($step)) {
                continue;
            }

            $onStart?->__invoke($step);
            $this->runStep($context, $this->step($step), $onResult);
        }

        if ($testsRunning !== null && $tests instanceof AsyncStep) {
            $result = $this->attempt(PreflightStep::Tests, fn () => $tests->finish($context, $testsRunning->wait()));
            $this->record($context, $result->withDuration(microtime(true) - $testsStarted), $onResult);
        }

        $decision = $gate($context->results->get(PreflightStep::Tests) ?? StepResult::skipped(PreflightStep::Tests, SkipReason::Disabled));

        foreach ([PreflightStep::Browser, PreflightStep::Phpstan] as $step) {
            if (! $has($step) || $context->results->get($step) !== null) {
                continue;
            }

            if (! $decision->allowsAgents()) {
                $this->record($context, StepResult::skipped($step, SkipReason::GateAborted), $onResult);

                continue;
            }

            $onStart?->__invoke($step);
            $this->runStep($context, $this->step($step), $onResult);
        }

        return $decision;
    }

    private function step(PreflightStep $step): ?Step
    {
        return $this->steps->get($step->value);
    }

    /** @param Closure(StepResult): void $onResult */
    private function runStep(PreflightContext $context, ?Step $step, Closure $onResult): void
    {
        if ($step === null) {
            return;
        }

        $started = microtime(true);
        $result = $this->attempt($step->step(), fn () => $step->run($context));

        $this->record($context, $result->withDuration(microtime(true) - $started), $onResult);
    }

    /** @param Closure(StepResult): void $onResult */
    private function record(PreflightContext $context, StepResult $result, Closure $onResult): void
    {
        $context->record($result);
        $onResult($result);
    }

    /**
     * A crashing step must not end the run; it becomes an "error" step.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|StepResult
     */
    private function attempt(PreflightStep $step, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            return StepResult::error($step, $exception::class.': '.$exception->getMessage());
        }
    }
}
