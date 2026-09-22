<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

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
    case DatabaseSchema = 'database-schema';
    case CodeCandidates = 'code-candidates';

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
            self::DatabaseSchema => 'Database schema',
            self::CodeCandidates => 'Code candidates',
        };
    }

    /**
     * The preflight steps whose data the snapshot shows.
     *
     * @return list<PreflightStep>
     */
    public function steps(): array
    {
        return match ($this) {
            self::Status => [],
            self::Tests, self::Coverage => [PreflightStep::Tests],
            self::Browser => [PreflightStep::Browser],
            self::Phpstan => [PreflightStep::Phpstan],
            self::RouteTests => [PreflightStep::Routes, PreflightStep::Tests],
            self::Routes => [PreflightStep::Routes],
            self::Pint => [PreflightStep::Pint],
            self::Translations => [PreflightStep::Translations],
            self::Composer => [PreflightStep::Composer],
            self::DatabaseSchema => [PreflightStep::Migrations],
            self::CodeCandidates => [PreflightStep::Candidates],
        };
    }

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
            self::DatabaseSchema => 'Y',
            self::CodeCandidates => 'K',
        };
    }
}
