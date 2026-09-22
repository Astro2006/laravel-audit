<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final readonly class Rejection
{
    public function __construct(
        public string $id,
        public string $title,
        public string $reason,
    ) {}

    /** @return array{id: string, title: string, reason: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'reason' => $this->reason];
    }
}
