<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

/**
 * Token, cost and time usage of one or more agent calls.
 */
final readonly class Telemetry
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public ?float $costUsd = null,
        public int $turns = 0,
        public int $durationMs = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->costUsd === null && $other->costUsd === null ? null : ($this->costUsd ?? 0.0) + ($other->costUsd ?? 0.0),
            $this->turns + $other->turns,
            $this->durationMs + $other->durationMs,
        );
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cost_usd: float|null, turns: int, duration_s: float} */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cost_usd' => $this->costUsd === null ? null : round($this->costUsd, 4),
            'turns' => $this->turns,
            'duration_s' => round($this->durationMs / 1000, 1),
        ];
    }
}
