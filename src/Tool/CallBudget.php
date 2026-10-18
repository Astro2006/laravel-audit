<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

final class CallBudget
{
    public const string FILE = 'calls.json';

    public const array ALWAYS_ALLOWED = ['status', 'wait', 'finish', 'work'];

    public function __construct(
        private readonly string $path,
        private readonly int $max,
    ) {}

    /**
     * Counts this call.
     *
     * @return bool whether the run has had more calls than the budget allows
     */
    public function spent(): bool
    {
        $calls = Read::int(Json::readFile($this->path), 'calls') + 1;
        Json::writeFile($this->path, ['calls' => $calls]);

        return $calls > $this->max;
    }
}
