<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;

abstract class BaseAudit implements Audit
{
    public function promptFile(): string
    {
        return $this->type()->value.'.md';
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        return [];
    }

    /**
     * Checks every audit shares.
     *
     * @return array<string, string>
     */
    protected function commonChecks(): array
    {
        return [
            'custom' => 'A project-specific rule from the custom checks list.',
            'other' => 'Fits none of the checks above. Use sparingly.',
        ];
    }
}
