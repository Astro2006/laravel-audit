<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

use Astro2006\LaravelAudit\Contracts\StepData;
use Astro2006\LaravelAudit\Data\Steps\AboutData;
use Astro2006\LaravelAudit\Data\Steps\BrowserData;
use Astro2006\LaravelAudit\Data\Steps\ComposerData;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeData;
use Astro2006\LaravelAudit\Data\Steps\PhpstanData;
use Astro2006\LaravelAudit\Data\Steps\PintData;
use Astro2006\LaravelAudit\Data\Steps\RoutesData;
use Astro2006\LaravelAudit\Data\Steps\StackData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Data\Steps\TranslationsData;

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

    /** @return class-string<StepData> */
    public function dataClass(): string
    {
        return match ($this) {
            self::About => AboutData::class,
            self::Stack => StackData::class,
            self::LinesOfCode => LinesOfCodeData::class,
            self::Pint => PintData::class,
            self::Routes => RoutesData::class,
            self::Translations => TranslationsData::class,
            self::Composer => ComposerData::class,
            self::Tests => TestsData::class,
            self::Browser => BrowserData::class,
            self::Phpstan => PhpstanData::class,
        };
    }
}
