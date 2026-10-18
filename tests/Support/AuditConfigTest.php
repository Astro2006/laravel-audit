<?php

use Astro2006\LaravelAudit\Support\AuditConfig;

it('fills every key a published config leaves out from the package defaults', function () {
    // An old published config that replaces whole blocks.
    $config = AuditConfig::load([
        'agent' => ['driver' => 'opencode'],
        'preflight' => ['tests' => ['gate' => 'abort']],
    ]);

    expect($config->string('agent.driver'))->toBe('opencode')
        ->and($config->int('agent.timeout'))->toBe(900)
        ->and($config->int('agent.max_concurrency'))->toBe(1)
        ->and($config->bool('agent.verify.enabled'))->toBeTrue()
        ->and($config->string('preflight.tests.gate'))->toBe('abort')
        ->and($config->bool('preflight.tests.coverage'))->toBeTrue()
        ->and($config->int('preflight.tests.timeout'))->toBe(1800)
        ->and($config->string('preflight.browser.testsuite'))->toBe('Browser')
        ->and($config->int('max_findings_per_task'))->toBe(5)
        ->and($config->strings('agent.boost_tools'))->toContain('search-docs');
});

it('replaces lists instead of merging them', function () {
    $config = AuditConfig::load(['agent' => ['deny_read' => ['.env']], 'exclude_paths' => ['vendor']]);

    expect($config->strings('agent.deny_read'))->toBe(['.env'])
        ->and($config->strings('exclude_paths'))->toBe(['vendor']);
});

it('reads the short forms for audits and verify', function () {
    $config = AuditConfig::load(['audits' => ['frontend' => false], 'agent' => ['verify' => false]]);

    expect($config->array('audits.frontend'))->toBe(['enabled' => false, 'preflight' => ['about', 'stack', 'loc', 'routes', 'translations', 'candidates', 'tests', 'browser']])
        ->and($config->bool('audits.backend.enabled'))->toBeTrue()
        ->and($config->bool('agent.verify.enabled'))->toBeFalse();
});

it('still reads the long form of a published config', function () {
    $config = AuditConfig::load(['audits' => ['backend' => ['enabled' => false, 'preflight' => ['about']]]]);

    expect($config->array('audits.backend'))->toBe(['enabled' => false, 'preflight' => ['about']]);
});

it('names the legacy keys a published config still sets', function () {
    $config = AuditConfig::load(['agent' => ['effort' => 'high', 'drivers' => ['opencode' => ['model' => null]]]]);

    expect($config->legacyKeys())->toBe(['agent.effort' => 'agent.drivers.claude_code.effort'])
        ->and(AuditConfig::load([])->legacyKeys())->toBe([]);
});

it('names a published codex driver block as no longer read', function () {
    expect(AuditConfig::load(['agent' => ['drivers' => ['codex' => ['binary' => 'codex', 'allow_unrestricted_read' => false]]]])->legacyKeys())
        ->toBe(['agent.drivers.codex' => null]);
});

it('treats empty strings as not set', function () {
    $config = AuditConfig::load(['agent' => ['model' => '  '], 'preflight' => ['phpstan' => ['level' => '']]]);

    expect($config->nullableString('agent.model'))->toBeNull()
        ->and($config->nullableString('preflight.phpstan.level'))->toBeNull();
});

it('names removed keys without a replacement', function () {
    expect(AuditConfig::load(['agent' => ['verify' => ['min_severity' => 'high']]])->legacyKeys())->toBe(['agent.verify.min_severity' => null]);
});
