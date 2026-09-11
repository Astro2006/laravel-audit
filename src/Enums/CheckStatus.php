<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Failed = 'failed';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '✔',
            self::Info => 'ℹ',
            self::Warning => '⚠',
            self::Failed => '✘',
        };
    }
}
