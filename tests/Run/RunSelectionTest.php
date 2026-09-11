<?php

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Run\RunSelection;

$all = ['tests' => true, 'frontend' => true, 'backend' => true, 'dependencies' => true];

it('runs every configured audit by default', function () use ($all) {
    $selection = RunSelection::resolve([...$all, 'frontend' => false], [], []);

    expect($selection->auditValues())->toBe(['tests', 'backend', 'dependencies'])
        ->and($selection->without)->toBe([]);
});

it('narrows to explicit audits', function () use ($all) {
    expect(RunSelection::resolve($all, ['backend', 'frontend'], [])->auditValues())->toBe(['frontend', 'backend']);
});

it('removes audits and steps with --without, comma separated or repeated', function () use ($all) {
    $selection = RunSelection::resolve($all, [], ['frontend,browser', 'coverage']);

    expect($selection->auditValues())->toBe(['tests', 'backend', 'dependencies'])
        ->and($selection->without)->toBe([OptionalStep::Browser, OptionalStep::Coverage])
        ->and($selection->skips(OptionalStep::Browser))->toBeTrue()
        ->and($selection->includes(AuditType::Frontend))->toBeFalse();
});

it('rejects invalid combinations', function (array $explicit, array $without, string $message) use ($all) {
    expect(fn () => RunSelection::resolve($all, $explicit, $without))->toThrow(InvalidSelection::class, $message);
})->with([
    'unknown value' => [[], ['dusk'], 'Unknown --without value "dusk"'],
    'selected and excluded' => [['frontend'], ['frontend'], 'selected and excluded'],
    'nothing left' => [['backend'], ['tests,frontend,dependencies', 'backend'], 'selected and excluded'],
    'everything excluded' => [[], ['tests,frontend,backend,dependencies'], 'No audit left'],
]);

it('lists the preflight steps the audits need, in order', function () use ($all) {
    expect(RunSelection::resolve($all, ['dependencies'], [])->requiredSteps())
        ->toBe([PreflightStep::About, PreflightStep::Stack, PreflightStep::LinesOfCode, PreflightStep::Composer])
        ->and(RunSelection::resolve($all, ['frontend'], [])->requiredSteps())
        ->toContain(PreflightStep::Browser)
        ->not->toContain(PreflightStep::Tests);
});
