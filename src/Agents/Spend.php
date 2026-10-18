<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

final class Spend
{
    /**
     * @param  array<string, TaskOutcome>  $outcomes
     * @return float what the outcomes cost together, in USD
     */
    public static function of(array $outcomes): float
    {
        $total = 0.0;

        foreach ($outcomes as $outcome) {
            $total += $outcome->telemetry->costUsd ?? 0.0;
        }

        return $total;
    }

    /**
     * @param  array<string, TaskOutcome>  $outcomes
     * @return float what the next job will cost, judged by the ones that reported a cost; 0 before the first
     */
    public static function estimate(array $outcomes): float
    {
        $costs = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->telemetry->costUsd !== null && $outcome->telemetry->costUsd > 0) {
                $costs[] = $outcome->telemetry->costUsd;
            }
        }

        return $costs === [] ? 0.0 : array_sum($costs) / count($costs);
    }
}
