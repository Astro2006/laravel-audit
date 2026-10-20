<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Contracts\AsyncStep;
use Illuminate\Contracts\Process\InvokedProcess;

final class RunningStep
{
    private ?float $finishedAt = null;

    public function __construct(
        public readonly AsyncStep $step,
        public readonly InvokedProcess $process,
        private readonly float $startedAt,
    ) {}

    public function poll(): bool
    {
        if ($this->finishedAt !== null) {
            return false;
        }

        if ($this->process->running()) {
            return true;
        }

        $this->finishedAt = microtime(true);

        return false;
    }

    public function finished(): bool
    {
        return $this->finishedAt !== null;
    }

    public function duration(): float
    {
        return ($this->finishedAt ?? microtime(true)) - $this->startedAt;
    }
}
