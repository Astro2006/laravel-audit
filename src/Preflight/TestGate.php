<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
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
     * @param  Closure(TestsDTO): bool  $ask  asks the user; only called in "ask" mode with interaction
     */
    public function decide(?StepResult $tests, GateMode $mode, bool $force, bool $interactive, Closure $ask): GateDecision
    {
        $data = self::data($tests);

        if ($data === null) {
            return GateDecision::NotRun;
        }

        if ($data->failures() === 0) {
            return GateDecision::Passed;
        }

        return match (true) {
            $force => GateDecision::Forced,
            $mode === GateMode::Continue => GateDecision::Continued,
            $mode === GateMode::Abort, ! $interactive => GateDecision::Aborted,
            default => $ask($data) ? GateDecision::Continued : GateDecision::Aborted,
        };
    }

    /** Test data of a successful test step, or null. */
    public static function data(?StepResult $tests): ?TestsDTO
    {
        return $tests !== null && $tests->isOk() && $tests->data instanceof TestsDTO ? $tests->data : null;
    }
}
