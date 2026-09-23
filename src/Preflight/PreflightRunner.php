<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Contracts\AsyncStep;
use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\Steps\AboutStep;
use Astro2006\LaravelAudit\Preflight\Steps\BrowserStep;
use Astro2006\LaravelAudit\Preflight\Steps\CandidatesStep;
use Astro2006\LaravelAudit\Preflight\Steps\ComposerStep;
use Astro2006\LaravelAudit\Preflight\Steps\LinesOfCodeStep;
use Astro2006\LaravelAudit\Preflight\Steps\MigrationsStep;
use Astro2006\LaravelAudit\Preflight\Steps\PhpstanStep;
use Astro2006\LaravelAudit\Preflight\Steps\PintStep;
use Astro2006\LaravelAudit\Preflight\Steps\RoutesStep;
use Astro2006\LaravelAudit\Preflight\Steps\StackStep;
use Astro2006\LaravelAudit\Preflight\Steps\TestsStep;
use Astro2006\LaravelAudit\Preflight\Steps\TranslationsStep;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;

final class PreflightRunner
{
    /** @var Collection<string, Step> */
    private Collection $steps;

    /** @param iterable<Step> $steps */
    public function __construct(iterable $steps)
    {
        $this->steps = (new Collection($steps))->keyBy(fn (Step $step): string => $step->step()->value);
    }

    public static function standard(DatabaseGuard $guard, string $locale): self
    {
        return new self([
            new AboutStep,
            new StackStep,
            new LinesOfCodeStep,
            new PintStep,
            new RoutesStep,
            new TranslationsStep($locale),
            new ComposerStep,
            new MigrationsStep,
            new CandidatesStep,
            new TestsStep($guard),
            new BrowserStep($guard),
            new PhpstanStep,
        ]);
    }

    /**
     * @param  Closure(StepResult): void  $onResult  called after every step
     * @param  (Closure(PreflightStep): void)|null  $onStart
     */
    public function run(PreflightContext $context, Closure $onResult, ?Closure $onStart = null): void
    {
        $required = $context->selection->requiredSteps();
        $has = fn (PreflightStep $step): bool => in_array($step, $required, true) && $this->steps->has($step->value);
        $background = [PreflightStep::Tests, ...($context->config->bool('preflight.phpstan.parallel') ? [PreflightStep::Phpstan] : [])];

        /** @var array<string, array{AsyncStep, InvokedProcess, float}> $running */
        $running = [];

        foreach ($background as $step) {
            if (! $has($step)) {
                continue;
            }

            $async = $this->step($step);

            if (! $async instanceof AsyncStep) {
                if ($step === PreflightStep::Tests) {
                    $onStart?->__invoke($step);
                    $this->runStep($context, $async, $onResult);
                }

                continue;
            }

            $onStart?->__invoke($step);
            $started = $this->startInBackground($context, $async, $onResult);

            if ($started !== null) {
                $running[$step->value] = $started;
            }
        }

        foreach ($required as $step) {
            if ($step->isHeavy() || ! $has($step)) {
                continue;
            }

            $onStart?->__invoke($step);
            $this->runStep($context, $this->step($step), $onResult);
            $this->drain($running);
        }

        foreach ([PreflightStep::Tests, PreflightStep::Browser, PreflightStep::Phpstan] as $step) {
            if (Arr::has($running, $step->value)) {
                [$async, $process, $startedAt] = Arr::get($running, $step->value);
                $result = $this->attempt($step, fn () => $async->finish($context, $process->wait()));
                $this->record($context, $result->withDuration(microtime(true) - $startedAt), $onResult);

                continue;
            }

            if (! $has($step) || $context->results->get($step) !== null) {
                continue;
            }

            $onStart?->__invoke($step);
            $this->runStep($context, $this->step($step), $onResult);
        }
    }

    /**
     * Reads what the background processes wrote so far. A process whose
     * output pipe is full stops until someone reads it, and Symfony only
     * reads while it is asked for the status.
     *
     * @param  array<string, array{AsyncStep, InvokedProcess, float}>  $running
     */
    private function drain(array $running): void
    {
        foreach ($running as [, $process]) {
            $process->running();
        }
    }

    /**
     * Starts an async step in the background. Returns null when it finished
     * right away (skipped, crashed).
     *
     * @param  Closure(StepResult): void  $onResult
     * @return array{AsyncStep, InvokedProcess, float}|null
     */
    private function startInBackground(PreflightContext $context, AsyncStep $async, Closure $onResult): ?array
    {
        $startedAt = microtime(true);
        $started = $this->attempt($async->step(), fn () => $async->start($context));

        if ($started instanceof InvokedProcess) {
            return [$async, $started, $startedAt];
        }

        $this->record($context, $started->withDuration(microtime(true) - $startedAt), $onResult);

        return null;
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
