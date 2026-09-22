<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Data\Steps\AboutDTO;
use Astro2006\LaravelAudit\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Data\Steps\ComposerDTO;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeDTO;
use Astro2006\LaravelAudit\Data\Steps\MigrationsDTO;
use Astro2006\LaravelAudit\Data\Steps\PhpstanDTO;
use Astro2006\LaravelAudit\Data\Steps\PintDTO;
use Astro2006\LaravelAudit\Data\Steps\RoutesDTO;
use Astro2006\LaravelAudit\Data\Steps\StackDTO;
use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Data\Steps\TranslationsDTO;

enum PreflightStep: string
{
    case About = 'about';
    case Stack = 'stack';
    case LinesOfCode = 'loc';
    case Pint = 'pint';
    case Routes = 'routes';
    case Translations = 'translations';
    case Composer = 'composer';
    case Migrations = 'migrations';
    case Tests = 'tests';
    case Browser = 'browser';
    case Phpstan = 'phpstan';

    /**
     * Heavy steps compete for CPU and run one after another:
     * tests → browser → phpstan.
     */
    public function isHeavy(): bool
    {
        return in_array($this, [
            self::Tests,
            self::Browser,
            self::Phpstan,
        ], true);
    }

    /** @return class-string<StepDTO> */
    public function dtoClass(): string
    {
        return match ($this) {
            self::About => AboutDTO::class,
            self::Stack => StackDTO::class,
            self::LinesOfCode => LinesOfCodeDTO::class,
            self::Pint => PintDTO::class,
            self::Routes => RoutesDTO::class,
            self::Translations => TranslationsDTO::class,
            self::Composer => ComposerDTO::class,
            self::Migrations => MigrationsDTO::class,
            self::Tests => TestsDTO::class,
            self::Browser => BrowserDTO::class,
            self::Phpstan => PhpstanDTO::class,
        };
    }
}
