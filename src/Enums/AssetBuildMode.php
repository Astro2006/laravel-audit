<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum AssetBuildMode: string
{
    case IfMissing = 'if-missing';
    case Always = 'always';
    case Never = 'never';
}
