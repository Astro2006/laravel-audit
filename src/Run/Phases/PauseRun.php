<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Agents\RunPause;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Run\RunContext;
use Closure;

final class PauseRun
{
    private const int PROBE_SECONDS = 300;

    private readonly Closure $sleep;

    /** @param  (Closure(int): void)|null  $sleep  waits the given seconds; null = sleep() */
    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? sleep(...);
    }

    /**
     * Records the pause, tells the user how to resume and, at a usage limit
     * in an interactive run, offers to wait for the reset.
     *
     * @return bool whether the user waited and the run should continue now
     */
    public function handle(RunContext $context): bool
    {
        $pause = $context->manifest->pause;
        $console = $context->console;
        $messages = $console->messages;

        if ($pause === null) {
            return false;
        }

        $context->manifest->close(RunStatus::Paused);
        $console->live->release();
        $console->newLine();
        $console->line('  <fg=yellow>⚠</> '.$messages->get('pause.'.$pause->reason, ['message' => $pause->message]));

        if ($pause->resetsAt !== null) {
            $console->line('  '.$messages->get('pause.resets', ['time' => date('H:i', $pause->resetsAt)]));
        }

        $console->closing($messages->get('pause.resume', ['pending' => count($pause->pending), 'run' => $context->run->id]));

        if ($pause->reason !== RunPause::LIMIT || ! $console->interactive || ! $console->confirm('pause.question')) {
            return false;
        }

        $seconds = $pause->resetsAt === null ? self::PROBE_SECONDS : max(1, $pause->resetsAt - time());
        $console->line('  '.$messages->get('pause.waiting', ['minutes' => (int) ceil($seconds / 60)]));
        ($this->sleep)($seconds);

        return true;
    }
}
