<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum SkipReason: string
{
    case Without = 'without';
    case Disabled = 'disabled';
    case NotInstalled = 'not-installed';
    case NoConfig = 'no-config';
    case NoBrowserTests = 'no-browser-tests';
    case PluginMissing = 'plugin-missing';
    case SharedDatabase = 'shared-database';
}
