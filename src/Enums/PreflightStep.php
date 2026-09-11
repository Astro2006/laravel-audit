<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum PreflightStep: string
{
    case About = 'about';
    case Stack = 'stack';
    case LinesOfCode = 'loc';
    case Pint = 'pint';
    case Routes = 'routes';
    case Translations = 'translations';
    case Composer = 'composer';
    case Tests = 'tests';
    case Browser = 'browser';
    case Phpstan = 'phpstan';

    /**
     * Heavy steps compete for CPU and run one after another:
     * tests → gate → browser → phpstan.
     */
    public function isHeavy(): bool
    {
        return in_array($this, [self::Tests, self::Browser, self::Phpstan], true);
    }
}
