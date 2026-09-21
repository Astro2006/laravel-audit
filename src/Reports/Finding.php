<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\Severity;

final readonly class Finding
{
    /**
     * @param  list<Location>  $locations
     * @param  list<string>  $references
     * @param  list<string>  $related
     */
    public function __construct(
        public string $id,
        public string $title,
        public Severity $severity,
        public Confidence $confidence,
        public Category $category,
        public string $check,
        public Effort $effort,
        public array $locations,
        public int $occurrences,
        public array $references,
        public array $related,
        public string $evidence,
        public string $impact,
        public string $recommendation,
        public ?string $keySalt = null,
    ) {}

    /**
     * Stable identifier across runs: audit, check and every location target.
     * Line numbers are ignored because they shift with every edit; only
     * findings that would collide within one report get a salt.
     */
    public function key(string $audit): string
    {
        $targets = array_map(fn (Location $location): string => $location->target(), $this->locations);
        $targets = array_values(array_unique($targets));
        sort($targets);

        return substr(sha1($audit.'|'.$this->check.'|'.implode(',', $targets).($this->keySalt === null ? '' : '|'.$this->keySalt)), 0, 8);
    }

    /**
     * Gives findings that share a key a salt: first the start line of their
     * first location, then also their title.
     *
     * @param  list<self>  $findings
     * @return list<self>
     */
    public static function withUniqueKeys(array $findings): array
    {
        foreach ([
            fn (self $finding): string => (string) ($finding->locations[0]->line ?? ''),
            fn (self $finding): string => ($finding->locations[0]->line ?? '').'|'.mb_strtolower(trim($finding->title)),
        ] as $salt) {
            $counts = array_count_values(array_map(fn (self $finding): string => $finding->key(''), $findings));

            if (max([0, ...$counts]) < 2) {
                break;
            }

            $findings = array_map(
                fn (self $finding): self => ($counts[$finding->key('')] ?? 0) > 1 ? $finding->withKeySalt($salt($finding)) : $finding,
                $findings,
            );
        }

        return $findings;
    }

    public function withKeySalt(string $salt): self
    {
        return $this->with(keySalt: $salt);
    }

    public function downgraded(): self
    {
        return $this->with(severity: $this->severity->lower(), confidence: Confidence::Suspected);
    }

    private function with(?Severity $severity = null, ?Confidence $confidence = null, ?string $keySalt = null): self
    {
        return new self(
            $this->id, $this->title, $severity ?? $this->severity, $confidence ?? $this->confidence, $this->category, $this->check,
            $this->effort, $this->locations, $this->occurrences, $this->references, $this->related, $this->evidence, $this->impact,
            $this->recommendation, $keySalt ?? $this->keySalt,
        );
    }

    /**
     * @return array{id: string, title: string, severity: string, confidence: string, category: string, check: string, effort: string, locations: list<string>, occurrences: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence->value,
            'category' => $this->category->value,
            'check' => $this->check,
            'effort' => $this->effort->value,
            'locations' => array_map(fn (Location $location): string => (string) $location, $this->locations),
            'occurrences' => $this->occurrences,
        ];
    }
}
