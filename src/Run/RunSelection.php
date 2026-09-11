<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;

/**
 * Which audits run and which optional preflight parts are left out.
 */
final readonly class RunSelection
{
    /**
     * @param  list<AuditType>  $audits
     * @param  list<OptionalStep>  $without
     */
    public function __construct(
        public array $audits,
        public array $without = [],
    ) {}

    /**
     * @param  array<string, bool>  $configuredAudits  config('audit.audits')
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

        $base = $explicit !== []
            ? $explicit
            : array_keys(array_filter($configuredAudits));

        $audits = [];

        foreach (AuditType::cases() as $type) {
            if (in_array($type->value, $base, true) && ! in_array($type->value, $withoutValues, true)) {
                $audits[] = $type;
            }
        }

        if ($audits === []) {
            throw InvalidSelection::nothingLeft();
        }

        $steps = [];

        foreach (OptionalStep::cases() as $step) {
            if (in_array($step->value, $withoutValues, true)) {
                $steps[] = $step;
            }
        }

        return new self($audits, $steps);
    }

    public function includes(AuditType $audit): bool
    {
        return in_array($audit, $this->audits, true);
    }

    public function skips(OptionalStep $step): bool
    {
        return in_array($step, $this->without, true);
    }

    /**
     * Preflight steps the selected audits need, in execution order.
     * Steps left out with --without are still listed; they report "skipped".
     *
     * @return list<PreflightStep>
     */
    public function requiredSteps(): array
    {
        $needed = [];

        foreach ($this->audits as $audit) {
            foreach ($audit->audit()->preflightSteps() as $step) {
                $needed[$step->value] = true;
            }
        }

        return array_values(array_filter(
            PreflightStep::cases(),
            fn (PreflightStep $step): bool => isset($needed[$step->value]),
        ));
    }

    /** @return list<string> */
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
