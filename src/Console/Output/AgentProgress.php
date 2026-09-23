<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console\Output;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Support\Messages;
use Symfony\Component\Console\Terminal;

final class AgentProgress
{
    private readonly TaskBoard $board;

    private readonly int $width;

    private float $lastDrawn = 0.0;

    /** @param list<AgentJob> $jobs */
    public function __construct(array $jobs, private readonly LiveOutput $live, private readonly Messages $messages)
    {
        $this->board = new TaskBoard($jobs, $messages, microtime(true));
        $this->width = (new Terminal)->getWidth();
    }

    public function begin(): void
    {
        foreach ($this->board->plan() as $line) {
            $this->live->log($line);
        }

        $this->draw(true);
    }

    public function started(AgentJob $job): void
    {
        $this->board->started($job, microtime(true));

        if (! $this->live->enabled()) {
            $this->live->log('  <fg=gray>▶ '.$this->messages->get('board.started', ['task' => $job->key]).'</>');
        }

        $this->draw(true);
    }

    public function finished(TaskOutcome $outcome): void
    {
        $this->board->finished($outcome);

        $detail = $outcome->completed()
            ? $this->messages->get('agents.task_finished', ['findings' => count($outcome->findings), 'duration' => TaskBoard::duration($outcome->finishedAt - $outcome->startedAt)])
                .($outcome->rejected === [] ? '' : ' · '.$this->messages->get('agents.rejected', ['count' => count($outcome->rejected)]))
            : $this->messages->get('agents.failed', ['reason' => mb_strimwidth((string) $outcome->reason, 0, 70, '…')]);

        $this->live->log(sprintf('  <fg=%s>%s</> <fg=gray>%s · %s</>', $outcome->completed() ? 'green' : 'red', $outcome->completed() ? '✔' : '✘', $outcome->key, $detail));
        $this->draw(true);
    }

    public function repairing(AgentJob $job): void
    {
        $this->board->repairing($job);
        $this->draw(true);
    }

    public function tick(): void
    {
        $this->draw();
    }

    private function draw(bool $force = false): void
    {
        $now = microtime(true);

        if ($force || $now - $this->lastDrawn >= 1.0) {
            $this->lastDrawn = $now;
            $this->live->show($this->board->live($now, $this->width));
        }
    }
}
