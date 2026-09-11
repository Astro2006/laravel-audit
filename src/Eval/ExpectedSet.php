<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Support\Json;
use InvalidArgumentException;

/**
 * expected.json of the fixture app: planted problems and negative controls.
 */
final readonly class ExpectedSet
{
    /**
     * @param  list<ExpectedFinding>  $planted
     * @param  list<ExpectedFinding>  $controls
     */
    public function __construct(
        public array $planted,
        public array $controls,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Expected set [{$path}] not found.");
        }

        $data = Json::readFile($path);

        return new self(
            array_map(fn (array $item): ExpectedFinding => ExpectedFinding::fromArray($item), array_values((array) ($data['planted'] ?? []))),
            array_map(fn (array $item): ExpectedFinding => ExpectedFinding::fromArray($item), array_values((array) ($data['negative_controls'] ?? []))),
        );
    }

    /** @return list<ExpectedFinding> */
    public function plantedFor(string $audit): array
    {
        return array_values(array_filter($this->planted, fn (ExpectedFinding $expected): bool => $expected->audit === $audit));
    }

    /** @return list<ExpectedFinding> */
    public function controlsFor(string $audit): array
    {
        return array_values(array_filter($this->controls, fn (ExpectedFinding $expected): bool => $expected->audit === $audit));
    }
}
