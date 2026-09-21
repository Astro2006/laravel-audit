<?php

use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Audits\TestsAudit;
use Astro2006\LaravelAudit\Data\Steps\BrowserData;
use Astro2006\LaravelAudit\Data\Steps\PhpstanData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;

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

it('splits every audit into tasks that own each check and section exactly once', function (AuditType $type) {
    $audit = $type->audit();
    $tasks = $audit->tasks(withCustom: true);
    $keys = array_map(fn ($task) => $task->key(), $tasks);
    $owned = [];
    $sections = [];

    foreach ($tasks as $task) {
        foreach ($task->checks as $check) {
            expect($owned)->not->toHaveKey($check)
                ->and($audit->checks())->toHaveKey($check);
            $owned[$check] = $task->key();
        }

        foreach ($task->sections as $section) {
            expect($sections)->not->toHaveKey($section);
            $sections[$section] = $task->key();
        }

        foreach ($task->dependencyKeys() as $dependency) {
            expect($keys)->toContain($dependency)
                ->and(array_search($dependency, $keys, true))->toBeLessThan(array_search($task->key(), $keys, true));
        }

        expect(dirname(__DIR__, 2).'/resources/prompts/'.$task->promptFile())->toBeFile();
    }

    expect(array_keys($owned))->toEqualCanonicalizing(array_values(array_diff(array_keys($audit->checks()), ['other'])))
        ->and(array_keys($sections))->toEqualCanonicalizing($audit->sections())
        ->and($keys)->toBe(array_unique($keys))
        ->and(end($tasks)->isSummary())->toBeTrue()
        ->and(count($audit->tasks()))->toBe(count($tasks) - 1);
})->with(AuditType::cases());

it('gives every required finding a task that owns its check', function () {
    $summary = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsData(failed: 2)),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanData(baselined: 12)),
    ]));
    $owned = array_merge(...array_map(fn ($task) => $task->checks, (new TestsAudit)->tasks()));

    foreach ((new TestsAudit)->requiredFindings($summary) as $required) {
        expect($owned)->toContain($required->check);
    }

    foreach (['vulnerable-dependency', 'abandoned-package', 'lock-out-of-sync'] as $check) {
        expect(array_merge(...array_map(fn ($task) => $task->checks, (new DependenciesAudit)->tasks())))->toContain($check);
    }
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

it('skips tasks that have nothing to audit', function () {
    $clean = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsData(total: 10, passed: 10)),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanData(errors: 0)),
    ]));
    $broken = new PreflightSummary('run', GateDecision::Continued, new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsData(failed: 1)),
        StepResult::ok(PreflightStep::Phpstan, new PhpstanData(errors: 3)),
    ]));
    $task = fn (string $name) => collect((new TestsAudit)->tasks())->firstWhere('name', $name);

    expect((new TestsAudit)->skipReason($task('phpstan'), $clean))->toBe('PHPStan reports no errors and no baseline.')
        ->and((new TestsAudit)->skipReason($task('results'), $clean))->toBe('all tests passed and none were skipped.')
        ->and((new TestsAudit)->skipReason($task('coverage'), $clean))->toBeNull()
        ->and((new TestsAudit)->skipReason($task('phpstan'), $broken))->toBeNull()
        ->and((new TestsAudit)->skipReason($task('results'), $broken))->toBeNull();
});

function dependencyPreflight(array $facts): PreflightSummary
{
    $dir = tempDir('preflight');
    mkdir($dir.'/composer');
    Json::writeFile($dir.'/summary.json', ['run_id' => 'run', 'steps' => ['composer' => ['status' => 'ok']]]);
    file_put_contents($dir.'/composer/dependency-facts.json', json_encode($facts));

    return PreflightSummary::fromDirectory($dir);
}

it('skips the security task without advisories and abandoned packages', function () {
    $security = collect((new DependenciesAudit)->tasks())->firstWhere('name', 'security');

    expect((new DependenciesAudit)->skipReason($security, dependencyPreflight(['advisories' => [], 'abandoned' => []])))->toBe('composer audit reports no advisories and no abandoned packages.')
        ->and((new DependenciesAudit)->skipReason($security, dependencyPreflight(['advisories' => [['package' => 'guzzlehttp/psr7']]])))->toBeNull();
});

it('rejects dependency findings the preflight does not back', function () {
    $guard = (new DependenciesAudit)->findingGuard(
        dependencyPreflight(['advisories' => [['package' => 'guzzlehttp/psr7']], 'abandoned' => [['package' => 'acme/unused-lib']]]),
        new ProjectInspector(fixture('project')),
    );
    $finding = fn (string $check, string $location) => new Finding('DEP-001', 't', Severity::High, Confidence::Confirmed, Category::Security, $check, Effort::S, [Location::parse($location)], 1, [], [], 'e', 'i', 'r');

    expect($guard->reject($finding('vulnerable-dependency', 'package:guzzlehttp/psr7')))->toBeNull()
        ->and($guard->reject($finding('vulnerable-dependency', 'package:acme/used-lib')))->toBe('composer audit reports no advisory for `acme/used-lib`.')
        ->and($guard->reject($finding('abandoned-package', 'package:acme/unused-lib')))->toBeNull()
        ->and($guard->reject($finding('abandoned-package', 'package:acme/test-only')))->toBe('Composer reports no abandoned package `acme/test-only`.')
        ->and($guard->reject($finding('outdated-major', 'package:acme/made-up')))->toBe('`package:acme/made-up` is not in composer.lock.')
        ->and($guard->reject($finding('unsupported-runtime', 'package:php')))->toBeNull();
});
