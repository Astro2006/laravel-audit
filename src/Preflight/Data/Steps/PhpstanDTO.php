<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class PhpstanDTO implements StepDTO
{
    public function __construct(
        public ?string $level = null,
        public ?string $levelSource = null,
        public int $errors = 0,
        public int $baselined = 0,
        public int $identifiers = 0,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            Read::nullableString($data, 'level'),
            Read::nullableString($data, 'level_source'),
            Read::int($data, 'errors'),
            Read::int($data, 'baselined'),
            Read::int($data, 'identifiers'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'level_source' => $this->levelSource,
            'errors' => $this->errors,
            'baselined' => $this->baselined,
            'identifiers' => $this->identifiers,
            'file' => 'phpstan/phpstan-grouped.json',
        ];
    }
}
