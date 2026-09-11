<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Agents\Telemetry;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Mean and maximum of cost, tokens, turns and duration across runs.
 *
 * @implements Arrayable<string, array{mean: float, max: float}>
 */
final readonly class TelemetryStats implements Arrayable
{
    public function __construct(
        public float $costMean = 0.0,
        public float $costMax = 0.0,
        public float $inputMean = 0.0,
        public float $outputMean = 0.0,
        public float $turnsMean = 0.0,
        public float $durationMean = 0.0,
        public float $durationMax = 0.0,
    ) {}

    /** @param list<Telemetry> $telemetry */
    public static function of(array $telemetry): self
    {
        if ($telemetry === []) {
            return new self;
        }

        $values = fn (callable $field): array => array_map(fn (Telemetry $item): float => (float) $field($item), $telemetry);
        $mean = fn (array $list): float => round(array_sum($list) / count($list), 2);

        $cost = $values(fn (Telemetry $t): float => $t->costUsd ?? 0.0);
        $duration = $values(fn (Telemetry $t): float => $t->durationMs / 1000);

        return new self(
            costMean: $mean($cost),
            costMax: round(max($cost), 2),
            inputMean: $mean($values(fn (Telemetry $t): int => $t->inputTokens)),
            outputMean: $mean($values(fn (Telemetry $t): int => $t->outputTokens)),
            turnsMean: $mean($values(fn (Telemetry $t): int => $t->turns)),
            durationMean: $mean($duration),
            durationMax: round(max($duration), 2),
        );
    }

    /** @return array<string, array{mean: float, max: float}> */
    public function toArray(): array
    {
        return [
            'cost_usd' => ['mean' => $this->costMean, 'max' => $this->costMax],
            'input_tokens' => ['mean' => $this->inputMean, 'max' => $this->inputMean],
            'output_tokens' => ['mean' => $this->outputMean, 'max' => $this->outputMean],
            'turns' => ['mean' => $this->turnsMean, 'max' => $this->turnsMean],
            'duration_s' => ['mean' => $this->durationMean, 'max' => $this->durationMax],
        ];
    }
}
