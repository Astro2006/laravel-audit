<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Enums\Stage;

final readonly class AuditTask
{
    public const string SUMMARY = 'summary';

    public const string CUSTOM = 'custom';

    /**
     * @param  list<string>  $checks  check slugs this task owns
     * @param  list<string>  $sections  audit-specific report sections this task writes
     * @param  list<string>  $dependsOn  names of tasks in the same audit whose output this task needs
     * @param  list<SnapshotType>  $snapshots  preflight data shown in the prompt (the status of all steps is always shown)
     * @param  Stage  $stage  when an orchestrating agent runs the task: analyse, standards or security
     */
    public function __construct(
        public AuditType $audit,
        public string $name,
        public string $title,
        public array $checks,
        public array $sections = [],
        public array $dependsOn = [],
        public array $snapshots = [],
        public Stage $stage = Stage::Standards,
    ) {}

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

    public function promptFile(): string
    {
        return match ($this->name) {
            self::SUMMARY => 'shared/summary.md',
            self::CUSTOM => 'shared/custom.md',
            default => $this->audit->value.'/tasks/'.$this->name.'.md',
        };
    }

    public function extraPromptFile(): ?string
    {
        return $this->isSummary() ? $this->audit->value.'/tasks/summary.md' : null;
    }
}
