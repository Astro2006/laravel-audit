<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AgentExit;

final readonly class AgentResult
{
    public function __construct(
        public string $answer,
        public ?string $sessionId,
        public ?string $model,
        public Telemetry $telemetry,
        public AgentExit $exit,
        public bool $truncated = false,
        public ?string $error = null,
    ) {}

    public static function failed(AgentExit $exit, string $error, int $durationMs = 0): self
    {
        return new self('', null, null, new Telemetry(durationMs: $durationMs), $exit, error: $error);
    }

    public function succeeded(): bool
    {
        return $this->exit === AgentExit::Success && ! $this->truncated;
    }
}
