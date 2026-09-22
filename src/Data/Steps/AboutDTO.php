<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Steps;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Support\Read;

final readonly class AboutDTO implements StepDTO
{
    public function __construct(
        public ?string $name = null,
        public ?string $laravel = null,
        public ?string $php = null,
        public ?string $env = null,
        public ?bool $debug = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            Read::nullableString($data, 'name'),
            Read::nullableString($data, 'laravel'),
            Read::nullableString($data, 'php'),
            Read::nullableString($data, 'env'),
            data_get($data, 'debug') === null ? null : Read::bool($data, 'debug'),
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
            'file' => 'about.json',
        ];
    }
}
