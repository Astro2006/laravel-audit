<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class AboutDTO implements StepDTO
{
    /** @param  array<string, string>  $drivers  database, cache, queue, session, … as `artisan about` reports them */
    public function __construct(
        public ?string $name = null,
        public ?string $laravel = null,
        public ?string $php = null,
        public ?string $env = null,
        public ?bool $debug = null,
        public array $drivers = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            Read::nullableString($data, 'name'),
            Read::nullableString($data, 'laravel'),
            Read::nullableString($data, 'php'),
            Read::nullableString($data, 'env'),
            data_get($data, 'debug') === null ? null : Read::bool($data, 'debug'),
            Read::stringMap($data, 'drivers'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'laravel' => $this->laravel,
            'php' => $this->php,
            'env' => $this->env,
            'debug' => $this->debug,
            'drivers' => $this->drivers,
            'file' => 'about.json',
        ];
    }
}
