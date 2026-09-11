<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Reports\Finding;

/**
 * Experimental, no grade: Σ weight[severity] × factor[confidence].
 * Only meant to calibrate a later score formula.
 */
final class RiskPoints
{
    /**
     * @param  array<string, int|float>  $weights
     * @param  array<string, int|float>  $factors
     */
    public function __construct(
        private readonly array $weights,
        private readonly array $factors,
    ) {}

    /** @param list<Finding> $findings */
    public function total(array $findings): float
    {
        $points = 0.0;

        foreach ($findings as $finding) {
            $points += (float) ($this->weights[$finding->severity->value] ?? 0)
                * (float) ($this->factors[$finding->confidence->value] ?? 1);
        }

        return round($points, 1);
    }

    public static function perThousandLines(float $points, ?int $loc): ?float
    {
        return $loc === null || $loc === 0 ? null : round($points / $loc * 1000, 1);
    }
}
