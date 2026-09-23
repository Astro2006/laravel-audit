<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Phpstan;

use Illuminate\Contracts\Support\Arrayable;

/**
 * phpstan-grouped.json.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PhpstanReport implements Arrayable
{
    /**
     * @param  list<IdentifierGroup>  $identifiers
     * @param  list<IdentifierGroup>  $baselinedIdentifiers
     * @param  list<string>  $generalErrors
     */
    public function __construct(
        public string $level,
        public int $errors,
        public int $baselined,
        public array $identifiers,
        public array $baselinedIdentifiers,
        public array $generalErrors,
    ) {}

    public function identifier(string $identifier): ?IdentifierGroup
    {
        foreach ($this->identifiers as $group) {
            if ($group->identifier === $identifier) {
                return $group;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'errors' => $this->errors,
            'baselined' => $this->baselined,
            'identifiers' => array_map(fn (IdentifierGroup $group): array => $group->toArray(), $this->identifiers),
            'baselined_identifiers' => array_map(fn (IdentifierGroup $group): array => $group->toArray(), $this->baselinedIdentifiers),
            'general_errors' => $this->generalErrors,
        ];
    }
}
