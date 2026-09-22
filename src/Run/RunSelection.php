<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Illuminate\Support\Arr;

final readonly class RunSelection
{
    /**
     * @param  list<AuditType>  $audits
     * @param  list<OptionalStep>  $without
     * @param  array<string, list<PreflightStep>>  $steps  audit => its preflight steps from config('audit.audits.<audit>.preflight')
     */
    public function __construct(
        public array $audits,
        public array $without = [],
        public array $steps = [],
    ) {}

    /**
     * @param  array<string, mixed>  $configuredAudits  config('audit.audits')
     * @param  list<string>  $explicit  audits named with --tests, --frontend, …
     * @param  list<string>  $without  raw --without values, may be comma separated
     *
     * @throws InvalidSelection
     */
    public static function resolve(array $configuredAudits, array $explicit, array $without): self
    {
        $withoutValues = [];

        foreach ($without as $value) {
            foreach (explode(',', $value) as $part) {
                $part = strtolower(trim($part));

                if ($part !== '') {
                    $withoutValues[] = $part;
                }
            }
        }

        $allowed = [...AuditType::values(), ...OptionalStep::values()];

        foreach ($withoutValues as $value) {
            if (! in_array($value, $allowed, true)) {
                throw InvalidSelection::unknownWithout($value, $allowed);
            }
        }

        foreach ($explicit as $audit) {
            if (in_array($audit, $withoutValues, true)) {
                throw InvalidSelection::selectedAndExcluded($audit);
            }
        }

        $enabled = [];
        $steps = [];

        foreach (AuditType::cases() as $type) {
            $config = Arr::get($configuredAudits, $type->value, []);
            $config = is_array($config) ? $config : [];

            if ((bool) Arr::get($config, 'enabled', false)) {
                $enabled[] = $type->value;
            }

            $steps[$type->value] = self::steps($type, Arr::get($config, 'preflight', []));
        }

        $base = $explicit !== [] ? $explicit : $enabled;
        $audits = [];

        foreach (AuditType::cases() as $type) {
            if (in_array($type->value, $base, true) && ! in_array($type->value, $withoutValues, true)) {
                $audits[] = $type;
            }
        }

        if ($audits === []) {
            throw InvalidSelection::nothingLeft();
        }

        $without = [];

        foreach (OptionalStep::cases() as $step) {
            if (in_array($step->value, $withoutValues, true)) {
                $without[] = $step;
            }
        }

        return new self($audits, $without, $steps);
    }

    /**
     * @return list<PreflightStep>
     *
     * @throws InvalidSelection
     */
    private static function steps(AuditType $audit, mixed $values): array
    {
        $steps = [];

        foreach ((array) $values as $value) {
            $step = PreflightStep::tryFrom((string) $value)
                ?? throw InvalidSelection::unknownStep($audit->value, (string) $value, array_map(fn (PreflightStep $step): string => $step->value, PreflightStep::cases()));
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * The same audits, but only these preflight steps run (the steps an
     * orchestrator asked for).
     *
     * @param  list<PreflightStep>  $steps
     */
    public function onlySteps(array $steps): self
    {
        return new self($this->audits, $this->without, array_fill_keys($this->auditValues(), $steps));
    }

    public function includes(AuditType $audit): bool
    {
        return in_array($audit, $this->audits, true);
    }

    public function skips(OptionalStep $step): bool
    {
        return in_array($step, $this->without, true);
    }

    public function wantsCoverage(): bool
    {
        return $this->includes(AuditType::Tests) && ! $this->skips(OptionalStep::Coverage);
    }

    /**
     * Preflight steps the selected audits list in their config, in execution
     * order. Nothing else runs. Steps left out with --without are still
     * listed; they report "skipped".
     *
     * @return list<PreflightStep>
     */
    public function requiredSteps(): array
    {
        $needed = [];

        foreach ($this->audits as $audit) {
            array_push($needed, ...Arr::get($this->steps, $audit->value, []));
        }

        return array_values(array_filter(
            PreflightStep::cases(),
            fn (PreflightStep $step): bool => in_array($step, $needed, true),
        ));
    }

    public function needs(PreflightStep $step): bool
    {
        return in_array($step, $this->requiredSteps(), true);
    }

    /**
     * @return list<string>
     */
    public function withoutValues(): array
    {
        return array_map(fn (OptionalStep $step): string => $step->value, $this->without);
    }

    /** @return list<string> */
    public function auditValues(): array
    {
        return array_map(fn (AuditType $audit): string => $audit->value, $this->audits);
    }
}
