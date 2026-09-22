<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

/**
 * One kind of preflight data an agent sees as a compact snapshot in its
 * prompt, with one detail file per ID below preflight/details/<type>/.
 */
enum SnapshotType: string
{
    case Status = 'status';
    case Tests = 'tests';
    case Browser = 'browser';
    case Phpstan = 'phpstan';
    case Coverage = 'coverage';
    case RouteTests = 'route-tests';
    case Routes = 'routes';
    case Pint = 'pint';
    case Translations = 'translations';
    case Composer = 'composer';

    public function label(): string
    {
        return match ($this) {
            self::Status => 'Preflight steps',
            self::Tests => 'Tests',
            self::Browser => 'Browser tests',
            self::Phpstan => 'PHPStan',
            self::Coverage => 'Coverage',
            self::RouteTests => 'Routes and tests',
            self::Routes => 'Routes',
            self::Pint => 'Pint',
            self::Translations => 'Translations',
            self::Composer => 'Composer',
        };
    }

    /** Letter of the row IDs (T-01). One letter, so IDs never look like finding IDs (BE-001). */
    public function prefix(): string
    {
        return match ($this) {
            self::Status => 'S',
            self::Tests => 'T',
            self::Browser => 'B',
            self::Phpstan => 'P',
            self::Coverage => 'C',
            self::RouteTests => 'M',
            self::Routes => 'R',
            self::Pint => 'X',
            self::Translations => 'L',
            self::Composer => 'D',
        };
    }
}
