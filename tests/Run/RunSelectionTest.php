<?php

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Run\RunSelection;

it('runs every configured audit by default', function () {
    $selection = RunSelection::resolve(auditsConfig(['tests', 'backend', 'dependencies']), [], []);

    expect($selection->auditValues())->toBe(['tests', 'backend', 'dependencies'])
        ->and($selection->without)->toBe([]);
});

it('narrows to explicit audits', function () {
    expect(RunSelection::resolve(auditsConfig(), ['backend', 'frontend'], [])->auditValues())->toBe(['frontend', 'backend']);
});

it('removes audits and steps with --without, comma separated or repeated', function () {
    $selection = RunSelection::resolve(auditsConfig(), [], ['frontend,browser', 'coverage']);

    expect($selection->auditValues())->toBe(['tests', 'backend', 'dependencies'])
        ->and($selection->without)->toBe([OptionalStep::Browser, OptionalStep::Coverage])
        ->and($selection->skips(OptionalStep::Browser))->toBeTrue()
        ->and($selection->includes(AuditType::Frontend))->toBeFalse();
});

it('rejects invalid combinations', function (array $explicit, array $without, string $message) {
    expect(fn () => RunSelection::resolve(auditsConfig(), $explicit, $without))->toThrow(InvalidSelection::class, $message);
})->with([
    'unknown value' => [[], ['dusk'], 'Unknown --without value "dusk"'],
    'selected and excluded' => [['frontend'], ['frontend'], 'selected and excluded'],
    'nothing left' => [['backend'], ['tests,frontend,dependencies', 'backend'], 'selected and excluded'],
    'everything excluded' => [[], ['tests,frontend,backend,dependencies'], 'No audit left'],
]);

it('lists the preflight steps the selected audits configure, in execution order', function () {
    $all = auditsConfig();
    $all['dependencies']['preflight'] = ['composer', 'about'];

    expect(RunSelection::resolve($all, ['dependencies'], [])->requiredSteps())
        ->toBe([PreflightStep::About, PreflightStep::Composer])
        ->and(RunSelection::resolve($all, ['dependencies', 'frontend'], [])->requiredSteps())
        ->toContain(PreflightStep::Browser)
        ->not->toContain(PreflightStep::Phpstan);
});

it('runs no preflight step an audit does not list, not even the tests', function () {
    $all = auditsConfig();
    $all['dependencies']['preflight'] = [];
    $selection = RunSelection::resolve($all, ['dependencies'], []);

    expect($selection->requiredSteps())->toBe([])
        ->and($selection->needs(PreflightStep::Tests))->toBeFalse();
});

it('rejects unknown preflight steps', function () {
    $all = auditsConfig();
    $all['backend']['preflight'] = ['about', 'larastan'];

    expect(fn () => RunSelection::resolve($all, [], []))->toThrow(InvalidSelection::class, 'Unknown preflight step "larastan" in audit.audits.backend.preflight');
});
