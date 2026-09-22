<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;

final class Wait
{
    public function __construct(
        private readonly Status $status,
        private readonly int $pollMicroseconds = 1_000_000,
    ) {}

    /** @return array<string, mixed> */
    public function handle(OrchestratedRun $run, int $timeout): array
    {
        $until = microtime(true) + max(1, $timeout);

        while ($run->lock->current() !== null && microtime(true) < $until) {
            usleep($this->pollMicroseconds);
        }

        $done = $run->lock->current() === null;

        return [
            'done' => $done,
            ...($done ? [] : ['hint' => 'Still working. Run `wait` again.']),
            ...$this->status->handle($run),
        ];
    }
}
