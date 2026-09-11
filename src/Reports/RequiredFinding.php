<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * A preflight fact the report must cover, e.g. every security advisory.
 */
final readonly class RequiredFinding
{
    /**
     * @param  string|null  $target  exact location target (e.g. "package:vendor/name"); null = any location
     * @param  string|null  $targetPrefix  location target must start with this (e.g. "tests/Browser/")
     */
    public function __construct(
        public string $check,
        public string $description,
        public ?string $target = null,
        public ?string $targetPrefix = null,
    ) {}

    /** @param list<Finding> $findings */
    public function isCoveredBy(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->check !== $this->check) {
                continue;
            }

            if ($this->target === null && $this->targetPrefix === null) {
                return true;
            }

            foreach ($finding->locations as $location) {
                $target = $location->target();

                if ($this->target !== null && $target === $this->target) {
                    return true;
                }

                if ($this->targetPrefix !== null && str_starts_with($target, $this->targetPrefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
