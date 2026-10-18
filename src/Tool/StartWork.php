<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\Stage;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Run\Phases\SelectSkills;
use Astro2006\LaravelAudit\Skills\SkillInventory;
use Astro2006\LaravelAudit\Skills\SkillSelection;
use Astro2006\LaravelAudit\Skills\SkillSelector;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Read;
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
            return ['started' => [], 'already_done' => RunTasks::values($already), 'next' => $this->next($run)];
        }

        return $this->launch($run, WorkerLock::PREFLIGHT, RunTasks::values($todo), $sync, ['already_done' => RunTasks::values($already)]);
    }

    /** @return array<string, mixed> */
    public function tasks(OpenedRun $run, TaskSelection $selection, bool $sync = false): array
    {
        $preflight = $run->preflight();

        if ($preflight === null) {
            return ['error' => 'There is no preflight yet.', 'hint' => 'Run `preflight <step…>` and `wait` first.'];
        }

        if ($preflight->gate === GateDecision::Aborted) {
            return ['error' => 'The test gate stopped the run: tests or browser tests fail.', 'hint' => 'End the session; the user can restart with --force.'];
        }

        if ($selection->isEmpty()) {
            return ['error' => 'Name the tasks to start, or pass --stage, --pending or --failed.', 'hint' => 'See `catalog` for the task keys.'];
        }

        $tasks = RunTasks::for($run, $this->config);
        $skipReason = fn (AuditTask $task): ?string => Arr::get($tasks->audits, $task->key())->skipReason($task, $preflight);

        foreach ($selection->keys() as $key) {
            if (! $tasks->has($key)) {
                return ['error' => sprintf('Unknown task "%s".', $key), 'allowed' => array_keys($tasks->tasks)];
            }
        }

        try {
            $keys = array_values(array_unique([...$selection->keys(), ...$selection->expand($tasks, $run->store, $skipReason)]));
        } catch (InvalidSelection $exception) {
            return ['error' => $exception->getMessage(), 'allowed' => Stage::values()];
        }

        $maxAttempts = max(1, $this->config->int('agent.max_attempts'));
        $start = [];
        $skipped = [];
        $exhausted = [];
        $warnings = [];

        foreach ($keys as $key) {
            $task = Arr::get($tasks->tasks, $key);
            $reason = $skipReason($task);

            if ($reason !== null) {
                $skipped[$key] = $reason;

                continue;
            }

            if ($run->attempts->of($key) >= $maxAttempts) {
                $exhausted[$key] = sprintf('%d attempts; last reason: %s', $run->attempts->of($key), Read::string($run->store->record($key) ?? [], 'reason', 'none'));

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

        if ($start === [] && $exhausted !== []) {
            return ['error' => 'attempts exhausted', 'exhausted' => $exhausted, 'hint' => 'These tasks were started '.$maxAttempts.' times already. Leave them out and `finish`.'];
        }

        if ($start === []) {
            return ['started' => [], ...($skipped === [] ? [] : ['skipped' => $skipped]), 'next' => $this->next($run)];
        }

        [$skillWarnings, $skills] = $this->assignSkills($run, $selection->skills(), $start);
        $run->attempts->count($start);

        return $this->launch($run, WorkerLock::TASKS, $start, $sync, ['skipped' => $skipped, 'exhausted' => $exhausted, 'warnings' => [...$warnings, ...$skillWarnings], 'skills' => $skills]);
    }

    /**
     * The skills of the started tasks: the ones the session named, and the
     * harness's own selection (one agent call per run) for the others.
     *
     * @param  array<string, list<string>>  $named  task key => skill names
     * @param  list<string>  $start
     * @return array{list<string>, array<string, list<string>>} warnings, and the skills per started task
     */
    private function assignSkills(OpenedRun $run, array $named, array $start): array
    {
        $plan = $run->plan;
        $inventory = SkillInventory::scan($plan->project->basePath);
        $path = $run->run->dir().'/'.SkillSelection::FILE;
        $selection = SkillSelection::read($path);
        $warnings = [];

        if (array_diff($start, array_keys($named)) !== [] && ($selection === null || $selection->source === 'none')) {
            [$selection, $warnings] = (new SkillSelector($this->config))->select(SelectSkills::tasks($plan->selection->audits), $inventory, $plan->driver, $run->run, $plan->project);
        }

        $selection ??= new SkillSelection([], $inventory->hash(), '', 'agent');

        if ($named !== []) {
            [$valid, $dropped] = SkillSelection::validate($named, $start, $inventory, max(1, $this->config->int('skills_per_task')));
            $selection = $selection->with($valid);
            $warnings = $dropped === [] ? $warnings : [...$warnings, 'Skills left out: '.implode('; ', $dropped).'.'];
        }

        $selection->write($path);

        return [$warnings, array_intersect_key($selection->tasks, array_flip($start))];
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
            'next' => $this->next($run),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function next(OpenedRun $run): string
    {
        return (new NextStep($this->config))->for($run);
    }
}
