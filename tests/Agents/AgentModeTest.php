<?php

use Astro2006\LaravelAudit\Enums\AgentMode;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;

it('runs one task in single mode and the configured number in parallel mode', function () {
    expect(AgentMode::resolve('single')->concurrency(4))->toBe(1)
        ->and(AgentMode::resolve(' Parallel ')->concurrency(4))->toBe(4)
        ->and(AgentMode::Parallel->concurrency(0))->toBe(1);
});

it('rejects unknown modes', function () {
    AgentMode::resolve('turbo');
})->throws(InvalidSelection::class, 'Unknown agent mode "turbo". Allowed: single, parallel.');
