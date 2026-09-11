<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum GateDecision: string
{
    case Passed = 'passed';
    case Continued = 'continued';
    case Forced = 'forced';
    case Aborted = 'aborted';
    case NotRun = 'not-run';

    public function allowsAgents(): bool
    {
        return $this !== self::Aborted;
    }
}
