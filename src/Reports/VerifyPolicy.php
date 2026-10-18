<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Support\AuditConfig;

final readonly class VerifyPolicy
{
    /**
     * @param  int  $threshold  severity rank minus confidence rank from which a finding is verified
     * @param  list<string>  $alwaysCategories  categories verified from medium up unless confirmed
     */
    public function __construct(
        private int $threshold = 2,
        private array $alwaysCategories = ['security', 'correctness'],
    ) {}

    public static function fromConfig(AuditConfig $config): self
    {
        return new self(max(0, $config->int('agent.verify.threshold')), $config->strings('agent.verify.always_categories'));
    }

    public function needsVerify(Finding $finding): bool
    {
        if ($finding->severity->rank() - $finding->confidence->rank() >= $this->threshold) {
            return true;
        }

        return in_array($finding->category->value, $this->alwaysCategories, true)
            && $finding->severity->rank() >= Severity::Medium->rank()
            && $finding->confidence !== Confidence::Confirmed;
    }

    public function always(Category $category): bool
    {
        return in_array($category->value, $this->alwaysCategories, true);
    }
}
