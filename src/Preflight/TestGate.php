<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Closure;

final class TestGate
{
    /**
     * @param  Closure(): bool  $ask  asks the user; only called in "ask" mode with interaction
     */
    public function decide(StepResults $results, GateMode $mode, bool $force, bool $interactive, Closure $ask): GateDecision
    {
        if ($results->tests() === null && $results->browser() === null) {
            return GateDecision::NotRun;
        }

        if (self::failures($results) === 0) {
            return GateDecision::Passed;
        }

        return match (true) {
            $force => GateDecision::Forced,
            $mode === GateMode::Continue => GateDecision::Continued,
            $mode === GateMode::Abort, ! $interactive => GateDecision::Aborted,
            default => $ask() ? GateDecision::Continued : GateDecision::Aborted,
        };
    }

    public static function failures(StepResults $results): int
    {
        return ($results->tests()?->failures() ?? 0) + ($results->browser()?->failures() ?? 0);
    }
}
