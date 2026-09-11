<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How stable finding keys are across runs of the same code.
 *
 * @implements Arrayable<string, float|null>
 */
final readonly class Stability implements Arrayable
{
    public function __construct(
        public ?float $jaccard,
        public ?float $inAllRuns,
    ) {}

    /**
     * Mean pairwise Jaccard index of the key sets, and the share of keys that
     * appear in every run.
     *
     * @param  list<list<string>>  $keySets
     */
    public static function of(array $keySets): self
    {
        if (count($keySets) < 2) {
            return new self(null, null);
        }

        $scores = [];

        foreach ($keySets as $i => $a) {
            foreach (array_slice($keySets, $i + 1) as $b) {
                $union = count(array_unique([...$a, ...$b]));
                $scores[] = $union === 0 ? 1.0 : count(array_intersect($a, $b)) / $union;
            }
        }

        $union = array_unique(array_merge(...$keySets));
        $intersection = array_intersect(...$keySets);

        return new self(
            round(array_sum($scores) / count($scores), 2),
            $union === [] ? null : round(count($intersection) / count($union), 2),
        );
    }

    /** @return array{jaccard: float|null, in_all_runs: float|null} */
    public function toArray(): array
    {
        return ['jaccard' => $this->jaccard, 'in_all_runs' => $this->inAllRuns];
    }
}
