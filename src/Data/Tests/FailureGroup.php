<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Failing tests that share an exception type and message.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FailureGroup implements Arrayable
{
    /**
     * @param  list<string>  $tests  at most five test names
     * @param  list<string>  $files
     */
    public function __construct(
        public string $exception,
        public string $message,
        public int $count,
        public array $tests,
        public array $files,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Read::string($data, 'exception'),
            Read::string($data, 'message'),
            Read::int($data, 'count'),
            Read::strings($data, 'tests'),
            Read::strings($data, 'files'),
        );
    }

    public function label(): string
    {
        return $this->exception.': '.$this->message;
    }

    /** @return array{exception: string, message: string, count: int, tests: list<string>, files: list<string>} */
    public function toArray(): array
    {
        return [
            'exception' => $this->exception,
            'message' => $this->message,
            'count' => $this->count,
            'tests' => $this->tests,
            'files' => $this->files,
        ];
    }
}
