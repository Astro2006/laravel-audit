<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Closure;

/**
 * Decides whether agents may run when tests fail. Default is "no": audits
 * on a broken environment waste tokens.
 */
final class TestGate
{
    /**
     * @param  Closure(StepResult): bool  $ask  asks the user; only called in "ask" mode with interaction
     */
    public function decide(?StepResult $tests, GateMode $mode, bool $force, bool $interactive, Closure $ask): GateDecision
    {
        if ($tests === null || ! $tests->isOk()) {
            return GateDecision::NotRun;
        }

        if (self::failures($tests) === 0) {
            return GateDecision::Passed;
        }

        return match (true) {
            $force => GateDecision::Forced,
            $mode === GateMode::Continue => GateDecision::Continued,
            $mode === GateMode::Abort, ! $interactive => GateDecision::Aborted,
            default => $ask($tests) ? GateDecision::Continued : GateDecision::Aborted,
        };
    }

    public static function failures(StepResult $tests): int
    {
        return (int) $tests->get('failed', 0) + (int) $tests->get('errors', 0);
    }
}
