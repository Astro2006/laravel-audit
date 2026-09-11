<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;

/**
 * One small, focused piece of an audit: a few related checks that read the
 * same code, run as one agent call. Tasks run in parallel unless they
 * depend on another task's output (its sections are injected as context).
 * Every audit ends with a summary task that only sees the merged findings.
 */
final readonly class AuditTask
{
    public const string SUMMARY = 'summary';

    public const string CUSTOM = 'custom';

    /**
     * @param  list<string>  $checks  check slugs this task owns
     * @param  list<string>  $sections  audit-specific report sections this task writes
     * @param  list<string>  $dependsOn  names of tasks in the same audit whose output this task needs
     */
    public function __construct(
        public AuditType $audit,
        public string $name,
        public string $title,
        public array $checks,
        public array $sections = [],
        public array $dependsOn = [],
    ) {}

    /** Unique across the run, e.g. backend.injection. */
    public function key(): string
    {
        return $this->audit->value.'.'.$this->name;
    }

    /** @return list<string> */
    public function dependencyKeys(): array
    {
        return array_map(fn (string $name): string => $this->audit->value.'.'.$name, $this->dependsOn);
    }

    public function isSummary(): bool
    {
        return $this->name === self::SUMMARY;
    }

    /** Template below resources/prompts. Summary and custom tasks share one per kind. */
    public function promptFile(): string
    {
        return match ($this->name) {
            self::SUMMARY => 'tasks/_summary.md',
            self::CUSTOM => 'tasks/_custom.md',
            default => 'tasks/'.$this->audit->value.'/'.$this->name.'.md',
        };
    }

    /** Optional audit-specific addition to a shared template (e.g. tasks/tests/summary.md). */
    public function extraPromptFile(): ?string
    {
        return $this->isSummary() ? 'tasks/'.$this->audit->value.'/summary.md' : null;
    }
}
