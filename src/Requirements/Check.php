<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Requirements;

use Astro2006\LaravelAudit\Enums\CheckStatus;

final readonly class Check
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(CheckStatus::Ok, $message);
    }

    public static function info(string $message): self
    {
        return new self(CheckStatus::Info, $message);
    }

    public static function warning(string $message): self
    {
        return new self(CheckStatus::Warning, $message);
    }

    public static function failed(string $message): self
    {
        return new self(CheckStatus::Failed, $message);
    }
}
