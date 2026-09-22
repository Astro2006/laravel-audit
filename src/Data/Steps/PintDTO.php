<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class PintDTO implements StepDTO
{
    public function __construct(
        public int $filesWithIssues = 0,
        public int $rules = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(Read::int($data, 'files_with_issues'), Read::int($data, 'rules'));
    }

    /** @return array{files_with_issues: int, rules: int, file: string} */
    public function toArray(): array
    {
        return ['files_with_issues' => $this->filesWithIssues, 'rules' => $this->rules, 'file' => 'pint-grouped.json'];
    }
}
