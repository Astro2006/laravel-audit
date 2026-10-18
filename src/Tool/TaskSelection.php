<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\Stage;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Support\Read;
use Closure;

final readonly class TaskSelection
{
    /**
     * @param  list<string>  $items  task keys, each optionally followed by `:skill,skill`
     * @param  string|null  $stage  every task of this stage that has not run
     * @param  bool  $pending  every task that has not run
     * @param  bool  $failed  every task whose last attempt failed
     */
    public function __construct(
        public array $items = [],
        public ?string $stage = null,
        public bool $pending = false,
        public bool $failed = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [] && $this->stage === null && ! $this->pending && ! $this->failed;
    }

    /** @return list<string> the task keys named in the items, without duplicates */
    public function keys(): array
    {
        return array_values(array_unique(array_map(fn (string $item): string => (string) strtok($item, ':'), $this->items)));
    }

    /**
     * `backend.data:models,php` gives backend.data the skills models and php.
     *
     * @return array<string, list<string>> task key => skill names
     */
    public function skills(): array
    {
        $skills = [];

        foreach ($this->items as $item) {
            [$key, $names] = array_pad(explode(':', $item, 2), 2, '');

            if ($names !== '') {
                $skills[$key] = array_values(array_filter(array_map('trim', explode(',', $names))));
            }
        }

        return $skills;
    }

    /**
     * The task keys the stage, pending and failed flags add, in catalog order.
     *
     * @param  Closure(AuditTask): ?string  $skipReason
     * @return list<string>
     *
     * @throws InvalidSelection for an unknown stage
     */
    public function expand(RunTasks $tasks, TaskStore $store, Closure $skipReason): array
    {
        $stage = $this->stage === null ? null : Stage::tryFrom(strtolower(trim($this->stage)))
            ?? throw new InvalidSelection(sprintf('Unknown stage "%s". Allowed: %s.', $this->stage, implode(', ', Stage::values())));
        $keys = [];

        foreach ($tasks->tasks as $key => $task) {
            $record = $store->record($key);
            $notRun = $record === null && $skipReason($task) === null;
            $failed = $record !== null && Read::string($record, 'status') === AuditStatus::Failed->value;

            if (($notRun && ($this->pending || $task->stage === $stage)) || ($failed && $this->failed)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
