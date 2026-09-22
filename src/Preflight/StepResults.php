<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Data\Steps\AboutDTO;
use Astro2006\LaravelAudit\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Data\Steps\ComposerDTO;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeDTO;
use Astro2006\LaravelAudit\Data\Steps\MigrationsDTO;
use Astro2006\LaravelAudit\Data\Steps\PhpstanDTO;
use Astro2006\LaravelAudit\Data\Steps\PintDTO;
use Astro2006\LaravelAudit\Data\Steps\RoutesDTO;
use Astro2006\LaravelAudit\Data\Steps\StackDTO;
use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Data\Steps\TranslationsDTO;
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

    public function about(): ?AboutDTO
    {
        return $this->typed(PreflightStep::About, AboutDTO::class);
    }

    public function stack(): ?StackDTO
    {
        return $this->typed(PreflightStep::Stack, StackDTO::class);
    }

    public function linesOfCode(): ?LinesOfCodeDTO
    {
        return $this->typed(PreflightStep::LinesOfCode, LinesOfCodeDTO::class);
    }

    public function pint(): ?PintDTO
    {
        return $this->typed(PreflightStep::Pint, PintDTO::class);
    }

    public function routes(): ?RoutesDTO
    {
        return $this->typed(PreflightStep::Routes, RoutesDTO::class);
    }

    public function translations(): ?TranslationsDTO
    {
        return $this->typed(PreflightStep::Translations, TranslationsDTO::class);
    }

    public function migrations(): ?MigrationsDTO
    {
        return $this->typed(PreflightStep::Migrations, MigrationsDTO::class);
    }

    public function composer(): ?ComposerDTO
    {
        return $this->typed(PreflightStep::Composer, ComposerDTO::class);
    }

    public function tests(): ?TestsDTO
    {
        return $this->typed(PreflightStep::Tests, TestsDTO::class);
    }

    public function browser(): ?BrowserDTO
    {
        return $this->typed(PreflightStep::Browser, BrowserDTO::class);
    }

    public function phpstan(): ?PhpstanDTO
    {
        return $this->typed(PreflightStep::Phpstan, PhpstanDTO::class);
    }

    /**
     * @template T of StepDTO
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function typed(PreflightStep $step, string $class): ?StepDTO
    {
        $result = $this->get($step);

        return $result !== null && $result->isOk() && $result->data instanceof $class ? $result->data : null;
    }
}
