<?php

use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Audits\TestsAudit;
use Astro2006\LaravelAudit\Data\Steps\BrowserData;
use Astro2006\LaravelAudit\Data\Steps\PhpstanData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Support\Json;

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
    $summary = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsData(failed: 2)),
        StepResult::ok(PreflightStep::Browser, new BrowserData(failed: 1, path: 'tests/Browser')),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanData(baselined: 12)),
    ]));

    $required = (new TestsAudit)->requiredFindings($summary);

    [, $browser, $baseline] = $required;

    expect($required)->toHaveCount(3)
        ->and($browser->targetPrefix)->toBe('tests/Browser/')
        ->and($baseline->check)->toBe('phpstan-baseline');
});

it('does not require browser findings for environment errors or skipped steps', function () {
    $summary = new PreflightSummary('run', GateDecision::NotRun, new StepResults([
        StepResult::skipped(PreflightStep::Tests, SkipReason::SharedDatabase),
        StepResult::ok(PreflightStep::Browser, new BrowserData(failed: 18, environmentError: true)),
    ]));

    expect((new TestsAudit)->requiredFindings($summary))->toBe([]);
});

it('requires one finding per vulnerable and abandoned package', function () {
    $dir = tempDir('preflight');
    mkdir($dir.'/composer');
    Json::writeFile($dir.'/summary.json', ['run_id' => 'run', 'steps' => ['composer' => ['status' => 'ok']]]);
    file_put_contents($dir.'/composer/dependency-facts.json', json_encode([
        'advisories' => [
            ['package' => 'guzzlehttp/psr7', 'advisory' => 'GHSA-1'],
            ['package' => 'guzzlehttp/psr7', 'advisory' => 'GHSA-2'],
        ],
        'abandoned' => [['package' => 'fzaninotto/faker', 'replacement' => 'fakerphp/faker']],
        'validate' => ['lock_in_sync' => false],
    ]));

    $required = (new DependenciesAudit)->requiredFindings(PreflightSummary::fromDirectory($dir));

    expect(array_map(fn ($requirement) => [$requirement->check, $requirement->target], $required))->toBe([
        ['vulnerable-dependency', 'package:guzzlehttp/psr7'],
        ['abandoned-package', 'package:fzaninotto/faker'],
        ['lock-out-of-sync', null],
    ]);
});
