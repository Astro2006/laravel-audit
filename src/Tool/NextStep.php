<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\Stage;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class NextStep
{
    public function __construct(private readonly AuditConfig $config) {}

    /**
     * The command the harness would run now. An orchestrating agent that
     * follows it blindly still completes the run.
     *
     * @return string the full command, with the run ID
     */
    public function for(OpenedRun $run): string
    {
        if ($run->lock->current() !== null) {
            return $this->command($run, 'wait');
        }

        $preflight = $run->preflight();

        if ($preflight === null) {
            $steps = array_filter($run->plan->selection->requiredSteps(), fn (PreflightStep $step): bool => ! in_array($step, RunTasks::BASE_STEPS, true));

            return $this->command($run, 'preflight '.implode(' ', RunTasks::values(array_values($steps))));
        }

        if ($preflight->gate === GateDecision::Aborted) {
            return 'Tests fail: show the user the failures and ask whether to continue. Only on a yes: '.$this->command($run, 'continue');
        }

        $pause = Pause::read($run->run);

        if ($pause !== null && $pause->resetIsPending()) {
            return sprintf('The run is paused (%s). Tell the user; after %s: %s', $pause->message, date('H:i', (int) $pause->resetsAt), $this->command($run, 'start --pending'));
        }

        $tasks = RunTasks::for($run, $this->config);
        $failed = false;

        foreach (Stage::cases() as $stage) {
            foreach ($tasks->tasks as $key => $task) {
                $record = $run->store->record($key);

                if ($record === null && $task->stage === $stage && Arr::get($tasks->audits, $key)->skipReason($task, $preflight) === null) {
                    return $this->command($run, 'start --stage='.$stage->value);
                }

                $failed = $failed || ($record !== null && Read::string($record, 'status') === AuditStatus::Failed->value && $run->attempts->of($key) < $this->config->int('agent.max_attempts'));
            }
        }

        return $this->command($run, $failed ? 'start --failed' : 'finish');
    }

    private function command(OpenedRun $run, string $action): string
    {
        return sprintf('php artisan audit:tool %s --run=%s', $action, $run->run->id);
    }
}
