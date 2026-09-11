<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Contracts\StepData;
use Astro2006\LaravelAudit\Data\Steps\AboutData;
use Astro2006\LaravelAudit\Data\Steps\BrowserData;
use Astro2006\LaravelAudit\Data\Steps\ComposerData;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeData;
use Astro2006\LaravelAudit\Data\Steps\PhpstanData;
use Astro2006\LaravelAudit\Data\Steps\PintData;
use Astro2006\LaravelAudit\Data\Steps\RoutesData;
use Astro2006\LaravelAudit\Data\Steps\StackData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Data\Steps\TranslationsData;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;

/**
 * The results of all preflight steps, with typed access to their data.
 * Typed getters only return data of steps that ran successfully.
 */
final class StepResults
{
    /** @var list<StepResult> */
    private array $results = [];

    /** @param iterable<StepResult> $results */
    public function __construct(iterable $results = [])
    {
        foreach ($results as $result) {
            $this->put($result);
        }
    }

    public function put(StepResult $result): void
    {
        $this->results = [
            ...array_values(array_filter($this->results, fn (StepResult $existing): bool => $existing->step !== $result->step)),
            $result,
        ];
    }

    public function get(PreflightStep $step): ?StepResult
    {
        foreach ($this->results as $result) {
            if ($result->step === $step) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<StepResult> in the order of PreflightStep */
    public function all(): array
    {
        $ordered = [];

        foreach (PreflightStep::cases() as $step) {
            $result = $this->get($step);

            if ($result !== null) {
                $ordered[] = $result;
            }
        }

        return $ordered;
    }

    /** @return list<PreflightStep> */
    public function steps(): array
    {
        return array_map(fn (StepResult $result): PreflightStep => $result->step, $this->all());
    }

    public function status(PreflightStep $step): ?StepStatus
    {
        return $this->get($step)?->status;
    }

    public function ran(PreflightStep $step): bool
    {
        return $this->status($step) === StepStatus::Ok;
    }

    public function hasErrors(): bool
    {
        foreach ($this->results as $result) {
            if ($result->status === StepStatus::Error) {
                return true;
            }
        }

        return false;
    }

    public function about(): ?AboutData
    {
        return $this->typed(PreflightStep::About, AboutData::class);
    }

    public function stack(): ?StackData
    {
        return $this->typed(PreflightStep::Stack, StackData::class);
    }

    public function linesOfCode(): ?LinesOfCodeData
    {
        return $this->typed(PreflightStep::LinesOfCode, LinesOfCodeData::class);
    }

    public function pint(): ?PintData
    {
        return $this->typed(PreflightStep::Pint, PintData::class);
    }

    public function routes(): ?RoutesData
    {
        return $this->typed(PreflightStep::Routes, RoutesData::class);
    }

    public function translations(): ?TranslationsData
    {
        return $this->typed(PreflightStep::Translations, TranslationsData::class);
    }

    public function composer(): ?ComposerData
    {
        return $this->typed(PreflightStep::Composer, ComposerData::class);
    }

    public function tests(): ?TestsData
    {
        return $this->typed(PreflightStep::Tests, TestsData::class);
    }

    public function browser(): ?BrowserData
    {
        return $this->typed(PreflightStep::Browser, BrowserData::class);
    }

    public function phpstan(): ?PhpstanData
    {
        return $this->typed(PreflightStep::Phpstan, PhpstanData::class);
    }

    /**
     * @template T of StepData
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function typed(PreflightStep $step, string $class): ?StepData
    {
        $result = $this->get($step);

        return $result !== null && $result->isOk() && $result->data instanceof $class ? $result->data : null;
    }
}
