<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class LintResult
{
    /**
     * @param  list<string>  $errors  format errors; trigger the repair pass
     * @param  list<string>  $warnings  logged in run.md
     * @param  list<Finding>  $findings  every finding that passed validation
     */
    public function __construct(
        public array $errors,
        public array $warnings,
        public array $findings,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
