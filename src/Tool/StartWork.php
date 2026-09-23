<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Skills\SkillInventory;
use Astro2006\LaravelAudit\Skills\SkillSelection;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;

final class StartWork
{
    public function __construct(
        private readonly AuditConfig $config,
        private readonly Work $work,
    ) {}

    /**
     * @param  list<string>  $names  preflight step names
     * @param  bool  $sync  run the worker in this process (tests, debugging)
     * @return array<string, mixed>
     */
    public function preflight(OpenedRun $run, array $names, bool $sync = false): array
    {
        $steps = [];

        foreach ($names as $name) {
            $step = PreflightStep::tryFrom(strtolower(trim($name)));

            if ($step === null) {
                return ['error' => sprintf('Unknown preflight step "%s".', $name), 'allowed' => RunTasks::values(PreflightStep::cases())];
            }

            $steps[] = $step;
        }

        if ($steps === []) {
            return ['error' => 'Name the preflight steps to run.', 'allowed' => RunTasks::values(PreflightStep::cases())];
        }

        $done = $run->preflight()?->steps;
        $requested = RunTasks::ordered([...RunTasks::BASE_STEPS, ...$steps]);
        $todo = array_values(array_filter($requested, fn (PreflightStep $step): bool => $done?->get($step) === null));
        $already = array_values(array_filter($steps, fn (PreflightStep $step): bool => $done?->get($step) !== null));

        if ($todo === []) {
            return ['started' => [], 'already_done' => RunTasks::values($already)];
        }

        return $this->launch($run, WorkerLock::PREFLIGHT, RunTasks::values($todo), $sync, ['already_done' => RunTasks::values($already)]);
    }

    /**
     * @param  list<string>  $keys  task keys, e.g. backend.injection
     * @return array<string, mixed>
     */
    public function tasks(OpenedRun $run, array $keys, bool $sync = false): array
    {
        $preflight = $run->preflight();

        if ($preflight === null) {
            return ['error' => 'There is no preflight yet.', 'hint' => 'Run `preflight <step…>` and `wait` first.'];
        }

        if ($preflight->gate === GateDecision::Aborted) {
            return ['error' => 'The test gate stopped the run: tests or browser tests fail.', 'hint' => 'End the session; the user can restart with --force.'];
        }

        if ($keys === []) {
            return ['error' => 'Name the tasks to start.', 'hint' => 'See `catalog` for the task keys.'];
        }

        $tasks = RunTasks::for($run, $this->config);
        [$keys, $skills] = self::splitSkills($keys);
        $start = [];
        $skipped = [];
        $warnings = [];

        foreach (array_values(array_unique($keys)) as $key) {
            if (! $tasks->has($key)) {
                return ['error' => sprintf('Unknown task "%s".', $key), 'allowed' => array_keys($tasks->tasks)];
            }

            $task = Arr::get($tasks->tasks, $key);
            $reason = Arr::get($tasks->audits, $key)->skipReason($task, $preflight);

            if ($reason !== null) {
                $skipped[$key] = $reason;

                continue;
            }

            $missing = array_filter($tasks->steps($task), fn (PreflightStep $step): bool => $preflight->steps->get($step) === null);

            if ($missing !== []) {
                $warnings[] = sprintf('%s reads preflight steps that did not run: %s.', $key, implode(', ', RunTasks::values(array_values($missing))));
            }

            foreach ($task->dependencyKeys() as $dependency) {
                if (! in_array($dependency, $keys, true) && $run->store->record($dependency) === null) {
                    $warnings[] = sprintf('%s builds on %s, which has not run.', $key, $dependency);
                }
            }

            $start[] = $key;
        }

        if ($start === []) {
            return ['started' => [], 'skipped' => $skipped];
        }

        $warnings = [...$warnings, ...$this->assignSkills($run, $skills, $start)];

        return $this->launch($run, WorkerLock::TASKS, $start, $sync, ['skipped' => $skipped, 'warnings' => $warnings]);
    }

    /**
     * `backend.data:models,php` starts backend.data with the skills models and php.
     *
     * @param  list<string>  $items
     * @return array{list<string>, array<string, list<string>>} task keys, task key => skill names
     */
    private static function splitSkills(array $items): array
    {
        $keys = [];
        $skills = [];

        foreach ($items as $item) {
            [$key, $names] = array_pad(explode(':', $item, 2), 2, '');
            $keys[] = $key;

            if ($names !== '') {
                $skills[$key] = array_values(array_filter(array_map('trim', explode(',', $names))));
            }
        }

        return [$keys, $skills];
    }

    /**
     * @param  array<string, list<string>>  $skills
     * @param  list<string>  $start
     * @return list<string> warnings about skills that were left out
     */
    private function assignSkills(OpenedRun $run, array $skills, array $start): array
    {
        if ($skills === []) {
            return [];
        }

        $inventory = SkillInventory::scan($run->plan->project->basePath);
        $path = $run->run->dir().'/'.SkillSelection::FILE;
        [$valid, $dropped] = SkillSelection::validate($skills, $start, $inventory, max(1, $this->config->int('skills_per_task')));
        (SkillSelection::read($path) ?? new SkillSelection([], $inventory->hash(), '', 'agent'))->with($valid)->write($path);

        return $dropped === [] ? [] : ['Skills left out: '.implode('; ', $dropped).'.'];
    }

    /**
     * @param  list<string>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function launch(OpenedRun $run, string $kind, array $items, bool $sync, array $extra): array
    {
        if (! $run->lock->acquire($kind, $items)) {
            $lock = $run->lock->current();

            return ['error' => 'busy', 'working_on' => Arr::get($lock, 'kind'), 'items' => Arr::get($lock, 'items', []), 'hint' => 'Run `wait` until the worker is idle.'];
        }

        if ($sync) {
            $this->work->handle($run);
        } else {
            $log = $run->run->logsDir().'/worker-'.$kind.'.log';

            Process::path(base_path())->start(['sh', '-c', sprintf(
                'nohup %s %s audit:tool work --run=%s >> %s 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($run->run->id),
                escapeshellarg($log),
            )])->wait();
        }

        return array_filter([
            'started' => $items,
            ...$extra,
            'hint' => $sync ? null : 'Running in the background. Run `wait` to block until it is done.',
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
