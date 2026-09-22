<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;

final readonly class AuditTask
{
    public const string SUMMARY = 'summary';

    public const string CUSTOM = 'custom';

    /**
     * @param  list<string>  $checks  check slugs this task owns
     * @param  list<string>  $sections  audit-specific report sections this task writes
     * @param  list<string>  $dependsOn  names of tasks in the same audit whose output this task needs
     * @param  list<SnapshotType>  $snapshots  preflight data shown in the prompt (the status of all steps is always shown)
     */
    public function __construct(
        public AuditType $audit,
        public string $name,
        public string $title,
        public array $checks,
        public array $sections = [],
        public array $dependsOn = [],
        public array $snapshots = [],
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
            self::SUMMARY => 'agents/_summary.md',
            self::CUSTOM => 'agents/_custom.md',
            default => 'agents/'.$this->audit->value.'/'.$this->name.'.md',
        };
    }

    public function extraPromptFile(): ?string
    {
        return $this->isSummary() ? 'agents/'.$this->audit->value.'/summary.md' : null;
    }
}
