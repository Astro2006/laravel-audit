<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Agents\RunPause;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\Json;

final class Pause
{
    public const string FILE = 'pause.json';

    public static function write(AuditRun $run, RunPause $pause): void
    {
        Json::writeFile(self::path($run), $pause->toArray());
    }

    public static function read(AuditRun $run): ?RunPause
    {
        $data = Json::readFile(self::path($run));

        return $data === [] ? null : RunPause::fromArray($data);
    }

    public static function clear(AuditRun $run): void
    {
        if (is_file(self::path($run))) {
            unlink(self::path($run));
        }
    }

    private static function path(AuditRun $run): string
    {
        return OpenedRun::directory($run).'/'.self::FILE;
    }
}
