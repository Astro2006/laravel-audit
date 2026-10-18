<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Composer\InstalledVersions;
use Throwable;

final class PackageVersion
{
    public static function get(): string
    {
        try {
            return InstalledVersions::isInstalled('astro2006/laravel-audit')
                ? (string) InstalledVersions::getPrettyVersion('astro2006/laravel-audit')
                : 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
