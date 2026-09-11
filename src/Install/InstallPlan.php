<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

final class InstallPlan
{
    /**
     * @param  list<string>  $present  relevant packages that are already installed
     * @param  list<PlannedPackage>  $packages
     * @param  list<string>  $setup  setup step keys: boost, playwright-browsers, phpstan-stub
     * @param  list<InstallNote>  $notes
     * @param  list<string>  $manual  things the installer cannot do
     */
    public function __construct(
        public array $present = [],
        public array $packages = [],
        public array $setup = [],
        public array $notes = [],
        public array $manual = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->packages === [] && $this->setup === [];
    }

    /** @return list<PlannedPackage> */
    public function composer(): array
    {
        return array_values(array_filter($this->packages, fn (PlannedPackage $package): bool => $package->manager === 'composer'));
    }

    /** @return list<PlannedPackage> */
    public function npm(): array
    {
        return array_values(array_filter($this->packages, fn (PlannedPackage $package): bool => $package->manager === 'npm'));
    }

    /**
     * Keeps only the given packages and drops everything that depends on
     * a package that is no longer part of the plan.
     *
     * @param  list<string>  $names
     */
    public function only(array $names): self
    {
        $kept = array_values(array_filter($this->packages, fn (PlannedPackage $package): bool => in_array($package->name, $names, true)));

        do {
            $count = count($kept);
            $keptNames = array_map(fn (PlannedPackage $package): string => $package->name, $kept);
            $kept = array_values(array_filter(
                $kept,
                fn (PlannedPackage $package): bool => $package->requires === null
                    || in_array($package->requires, $keptNames, true)
                    || in_array($package->requires, $this->present, true),
            ));
        } while (count($kept) !== $count);

        $keptNames = array_map(fn (PlannedPackage $package): string => $package->name, $kept);
        $setup = array_values(array_filter($this->setup, fn (string $step): bool => match ($step) {
            'boost' => in_array('laravel/boost', $keptNames, true) || in_array('laravel/boost', $this->present, true),
            'playwright-browsers' => in_array('playwright', $keptNames, true) || in_array('playwright', $this->present, true),
            default => true,
        }));

        return new self($this->present, $kept, $setup, $this->notes, $this->manual);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (PlannedPackage $package): string => $package->name, $this->packages);
    }
}
