<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Run\Data\FindingRecord;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;
use InvalidArgumentException;

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
            array_map(ExpectedFinding::fromArray(...), Read::arrays($data, 'planted')),
            array_map(ExpectedFinding::fromArray(...), Read::arrays($data, 'negative_controls')),
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

    public function explains(string $audit, FindingRecord $finding): bool
    {
        foreach ([...$this->plantedFor($audit), ...$this->controlsFor($audit)] as $expected) {
            if ($expected->matches($finding)) {
                return true;
            }
        }

        return false;
    }
}
