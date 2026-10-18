<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Composer;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * dependency-facts.json: everything Composer can tell, computed before the
 * dependencies agent starts.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DependencyFactsReport implements Arrayable
{
    /**
     * @param  list<Advisory>  $advisories
     * @param  list<AbandonedPackage>  $abandoned
     * @param  list<OutdatedPackage>  $outdatedMajor
     * @param  list<PackageCandidate>  $misplacedCandidates
     * @param  list<PackageCandidate>  $unusedCandidates
     * @param  list<PackageCandidate>  $shadowCandidates
     * @param  list<FlaggedLicense>  $licensesFlagged
     * @param  list<FlaggedConstraint>  $constraintsFlagged
     * @param  list<string>  $composerConfig
     */
    public function __construct(
        public array $advisories,
        public array $abandoned,
        public array $outdatedMajor,
        public array $misplacedCandidates,
        public array $unusedCandidates,
        public array $shadowCandidates,
        public array $licensesFlagged,
        public array $constraintsFlagged,
        public array $composerConfig,
        public ValidateResult $validate,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            advisories: array_map(Advisory::fromArray(...), Read::arrays($data, 'advisories')),
            abandoned: array_map(AbandonedPackage::fromArray(...), Read::arrays($data, 'abandoned')),
            outdatedMajor: array_map(OutdatedPackage::fromArray(...), Read::arrays($data, 'outdated_major')),
            misplacedCandidates: array_map(PackageCandidate::fromArray(...), Read::arrays($data, 'misplaced_candidates')),
            unusedCandidates: array_map(PackageCandidate::fromArray(...), Read::arrays($data, 'unused_candidates')),
            shadowCandidates: array_map(PackageCandidate::fromArray(...), Read::arrays($data, 'shadow_candidates')),
            licensesFlagged: array_map(FlaggedLicense::fromArray(...), Read::arrays($data, 'licenses_flagged')),
            constraintsFlagged: array_map(FlaggedConstraint::fromArray(...), Read::arrays($data, 'constraints_flagged')),
            composerConfig: Read::strings($data, 'composer_config'),
            validate: ValidateResult::fromArray(Read::array($data, 'validate')),
        );
    }

    /** @return list<string> unique packages with at least one advisory */
    public function advisoryPackages(): array
    {
        return array_values(array_unique(array_map(fn (Advisory $advisory): string => $advisory->package, $this->advisories)));
    }

    /** @return array{critical: int, high: int, medium: int, low: int} */
    public function advisoriesBySeverity(): array
    {
        $count = fn (string $severity): int => count(array_filter(
            $this->advisories,
            fn (Advisory $advisory): bool => strtolower((string) $advisory->severity) === $severity,
        ));

        return ['critical' => $count('critical'), 'high' => $count('high'), 'medium' => $count('medium'), 'low' => $count('low')];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $list = fn (array $items): array => array_map(fn (Arrayable $item): array => $item->toArray(), $items);

        return [
            'advisories' => $list($this->advisories),
            'abandoned' => $list($this->abandoned),
            'outdated_major' => $list($this->outdatedMajor),
            'misplaced_candidates' => $list($this->misplacedCandidates),
            'unused_candidates' => $list($this->unusedCandidates),
            'shadow_candidates' => $list($this->shadowCandidates),
            'licenses_flagged' => $list($this->licensesFlagged),
            'constraints_flagged' => $list($this->constraintsFlagged),
            'composer_config' => $this->composerConfig,
            'validate' => $this->validate->toArray(),
        ];
    }
}
