<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Support\Read;

/**
 * Experimental, no grade: Σ weight[severity] × factor[confidence].
 * Only meant to calibrate a later score formula.
 */
final class RiskPoints
{
    /**
     * @param  array<mixed>  $weights  config audit.risk_points.weights
     * @param  array<mixed>  $factors  config audit.risk_points.confidence
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
            $points += Read::float($this->weights, $finding->severity->value)
                * Read::float($this->factors, $finding->confidence->value, 1.0);
        }

        return round($points, 1);
    }

    public static function perThousandLines(float $points, ?int $loc): ?float
    {
        return $loc === null || $loc === 0 ? null : round($points / $loc * 1000, 1);
    }
}
