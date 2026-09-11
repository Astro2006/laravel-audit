<?php

use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Audits\TestsAudit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;

it('gives every check to exactly one audit', function () {
    $owners = [];

    foreach (AuditType::cases() as $type) {
        foreach (array_keys($type->audit()->checks()) as $check) {
            if (in_array($check, ['custom', 'other'], true)) {
                continue;
            }

            expect($owners)->not->toHaveKey($check);
            $owners[$check] = $type->value;
        }
    }

    expect(count($owners))->toBeGreaterThan(80);
});

it('uses unique id prefixes', function () {
    $prefixes = array_map(fn (AuditType $type) => $type->audit()->idPrefix(), AuditType::cases());

    expect($prefixes)->toBe(array_unique($prefixes));
});

it('requires test failure and baseline findings from the tests audit', function () {
    $summary = new PreflightSummary(['steps' => [
        'tests' => ['status' => 'ok', 'failed' => 2, 'errors' => 0],
        'browser' => ['status' => 'ok', 'failed' => 1, 'environment_error' => false, 'path' => 'tests/Browser'],
        'phpstan' => ['status' => 'ok', 'baselined' => 12],
    ]]);

    $required = (new TestsAudit)->requiredFindings($summary);

    expect($required)->toHaveCount(3)
        ->and($required[1]->targetPrefix)->toBe('tests/Browser/')
        ->and($required[2]->check)->toBe('phpstan-baseline');
});

it('does not require browser findings for environment errors or skipped steps', function () {
    $summary = new PreflightSummary(['steps' => [
        'tests' => ['status' => 'skipped', 'reason' => 'shared-database'],
        'browser' => ['status' => 'ok', 'failed' => 18, 'environment_error' => true],
    ]]);

    expect((new TestsAudit)->requiredFindings($summary))->toBe([]);
});

it('requires one finding per vulnerable and abandoned package', function () {
    $dir = tempDir('preflight');
    mkdir($dir.'/composer');
    file_put_contents($dir.'/composer/dependency-facts.json', json_encode([
        'advisories' => [
            ['package' => 'guzzlehttp/psr7', 'advisory' => 'GHSA-1'],
            ['package' => 'guzzlehttp/psr7', 'advisory' => 'GHSA-2'],
        ],
        'abandoned' => [['package' => 'fzaninotto/faker', 'replacement' => 'fakerphp/faker']],
        'validate' => ['lock_in_sync' => false],
    ]));

    $required = (new DependenciesAudit)->requiredFindings(PreflightSummary::fromDirectory($dir));

    expect(array_map(fn ($r) => [$r->check, $r->target], $required))->toBe([
        ['vulnerable-dependency', 'package:guzzlehttp/psr7'],
        ['abandoned-package', 'package:fzaninotto/faker'],
        ['lock-out-of-sync', null],
    ]);
});
