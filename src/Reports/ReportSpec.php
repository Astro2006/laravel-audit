<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Audits\Audit;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Illuminate\Support\Arr;

final readonly class ReportSpec
{
    /**
     * @param  string  $label  who writes it, for error messages: "backend" or "backend.injection"
     * @param  array<string, string>  $checks  allowed check slugs => description
     * @param  list<string>  $sections  required level-2 sections in order; Limitations, if listed, must come last
     * @param  int|null  $maxFindings  null = no limit
     * @param  FindingGuard|null  $guard  facts a finding must not contradict
     */
    public function __construct(
        public string $label,
        public string $idPrefix,
        public array $checks,
        public array $sections,
        public ?int $maxFindings = null,
        public ?FindingGuard $guard = null,
    ) {}

    public static function forAudit(Audit $audit, ?int $maxFindings = null, ?FindingGuard $guard = null): self
    {
        return new self(
            $audit->type()->value,
            $audit->idPrefix(),
            $audit->checks(),
            ['Summary', 'Findings', ...$audit->sections(), 'Positive observations', 'Limitations'],
            $maxFindings,
            $guard,
        );
    }

    public static function forTask(Audit $audit, AuditTask $task, ?int $maxFindings = null, ?FindingGuard $guard = null): self
    {
        if ($task->isSummary()) {
            return new self($task->key(), $audit->idPrefix(), [], ['Summary', ...$task->sections]);
        }

        $checks = array_intersect_key($audit->checks(), array_flip([...$task->checks, 'other']));

        return new self($task->key(), $audit->idPrefix(), $checks, ['Findings', ...$task->sections, 'Limitations'], $maxFindings, $guard);
    }

    public function hasFindings(): bool
    {
        return in_array('Findings', $this->sections, true);
    }

    public function firstSection(): string
    {
        return Arr::first($this->sections) ?? 'Findings';
    }
}
