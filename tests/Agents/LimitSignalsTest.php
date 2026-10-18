<?php

use Astro2006\LaravelAudit\Agents\LimitSignals;

it('recognises usage and rate limits and reads the reset time', function () {
    expect(LimitSignals::matches("You've reached your weekly usage limit."))->toBeTrue()
        ->and(LimitSignals::matches('API Error: 429 {"type":"error","error":{"type":"rate_limit_error"}}'))->toBeTrue()
        ->and(LimitSignals::matches('Model hy3-free is not supported'))->toBeFalse()
        ->and(LimitSignals::matches(null))->toBeFalse()
        ->and(LimitSignals::resetsAt('Claude AI usage limit reached|1799999999'))->toBe(1799999999)
        ->and(LimitSignals::resetsAt('Usage limit reached. Your limit resets 2026-08-08 00:00 UTC.'))->toBe(strtotime('2026-08-08 00:00 UTC'))
        ->and(LimitSignals::resetsAt('Usage limit reached, resets in 2 hours.'))->toBeGreaterThan(time() + 7000)
        ->and(LimitSignals::resetsAt('rate limited'))->toBeNull();
});
