<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Support\Read;

final readonly class RunPause
{
    public const string LIMIT = 'limit';

    public const string BUDGET = 'budget';

    /**
     * @param  string  $reason  limit or budget
     * @param  list<string>  $pending  jobs that did not run
     * @param  int|null  $resetsAt  unix time the limit resets, when known
     */
    public function __construct(
        public string $reason,
        public string $message,
        public array $pending = [],
        public ?int $resetsAt = null,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $resetsAt = Read::nullableString($data, 'resets_at');
        $time = $resetsAt === null ? false : strtotime($resetsAt);

        return new self(Read::string($data, 'reason'), Read::string($data, 'message'), Read::strings($data, 'pending'), $time === false ? null : $time);
    }

    /** @param list<string> $pending */
    public function withPending(array $pending): self
    {
        return new self($this->reason, $this->message, $pending, $this->resetsAt);
    }

    public function resetIsPending(): bool
    {
        return $this->resetsAt !== null && $this->resetsAt > time();
    }

    /** @return array{reason: string, message: string, pending: list<string>, resets_at: string|null} */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->message,
            'pending' => $this->pending,
            'resets_at' => $this->resetsAt === null ? null : date(DATE_ATOM, $this->resetsAt),
        ];
    }
}
