<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Steps;

use Astro2006\LaravelAudit\Agents\DependenciesAgent;
use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class ComposerDTO implements StepDTO
{
    public function __construct(
        public int $direct = 0,
        public int $advisories = 0,
        public int $advisoryPackages = 0,
        public int $abandoned = 0,
        public int $outdatedMajor = 0,
        public int $unusedCandidates = 0,
        public bool $lockInSync = true,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            Read::int($data, 'direct'),
            Read::int($data, 'advisories'),
            Read::int($data, 'advisory_packages'),
            Read::int($data, 'abandoned'),
            Read::int($data, 'outdated_major'),
            Read::int($data, 'unused_candidates'),
            Read::bool($data, 'lock_in_sync', true),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'direct' => $this->direct,
            'advisories' => $this->advisories,
            'advisory_packages' => $this->advisoryPackages,
            'abandoned' => $this->abandoned,
            'outdated_major' => $this->outdatedMajor,
            'unused_candidates' => $this->unusedCandidates,
            'lock_in_sync' => $this->lockInSync,
            'file' => DependenciesAgent::FACTS_FILE,
        ];
    }
}
