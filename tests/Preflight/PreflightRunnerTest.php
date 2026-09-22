<?php

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Contracts\StepDTO;
use Astro2006\LaravelAudit\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\PreflightRunner;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function fakeStep(PreflightStep $step, ?StepDTO $data = null, ?Closure $run = null): Step
{
    return new class($step, $data, $run) implements Step
    {
        public function __construct(private PreflightStep $step, private ?StepDTO $data, private ?Closure $callback) {}

        public function step(): PreflightStep
        {
            return $this->step;
        }

        public function run(PreflightContext $context): StepResult
        {
            if ($this->callback !== null) {
                return ($this->callback)();
            }

            $class = $this->step->dtoClass();

            return StepResult::ok($this->step, $this->data ?? new $class);
        }
    };
}

/** @return list<Step> */
function fakeSteps(?TestsDTO $tests = null): array
{
    return array_map(
        fn (PreflightStep $step) => fakeStep($step, $step === PreflightStep::Tests ? $tests : null),
        PreflightStep::cases(),
    );
}

function runnerContext(array $explicit = []): PreflightContext
{
    $dir = tempDir('run');

    return new PreflightContext(
        new ProjectInspector(fixture('project')),
        $dir.'/preflight',
        $dir.'/logs',
        auditConfig(),
        RunSelection::resolve(auditsConfig(), $explicit, []),
    );
}

it('runs tests first (async in real runs), light steps, then browser and phpstan', function () {
    $order = [];

    (new PreflightRunner(fakeSteps()))->run(
        runnerContext(),
        function (StepResult $result) use (&$order) {
            $order[] = $result->step->value;
        },
    );

    expect($order)->toBe(['tests', 'about', 'stack', 'loc', 'pint', 'routes', 'translations', 'composer', 'browser', 'phpstan']);
});

it('runs browser tests and phpstan even when tests fail', function () {
    $context = runnerContext();

    (new PreflightRunner(fakeSteps(new TestsDTO(failed: 3))))->run($context, fn () => null);

    expect($context->results->ran(PreflightStep::Browser))->toBeTrue()
        ->and($context->results->ran(PreflightStep::Phpstan))->toBeTrue();
});

it('runs only the steps the selected audits need', function () {
    $context = runnerContext(['dependencies']);

    (new PreflightRunner(fakeSteps()))->run($context, fn () => null);

    expect($context->results->steps())->toBe([PreflightStep::About, PreflightStep::Stack, PreflightStep::Composer]);
});

it('turns a crashing step into an error result', function () {
    $context = runnerContext(['dependencies']);
    $steps = [
        fakeStep(PreflightStep::About),
        fakeStep(PreflightStep::Composer, run: fn () => throw new RuntimeException('boom')),
    ];

    (new PreflightRunner($steps))->run($context, fn () => null);

    expect($context->results->get(PreflightStep::Composer)->error)->toBe('RuntimeException: boom')
        ->and($context->results->hasErrors())->toBeTrue();
});

function gateSteps(?StepResult ...$results): StepResults
{
    return new StepResults(array_filter($results));
}

it('decides the test gate', function (StepResults $results, GateMode $mode, bool $force, bool $interactive, bool $answer, GateDecision $expected) {
    expect((new TestGate)->decide($results, $mode, $force, $interactive, fn () => $answer))->toBe($expected);
})->with([
    'no tests' => fn () => [gateSteps(), GateMode::Ask, false, true, false, GateDecision::NotRun],
    'skipped' => fn () => [gateSteps(StepResult::skipped(PreflightStep::Tests, SkipReason::SharedDatabase)), GateMode::Ask, false, true, false, GateDecision::NotRun],
    'green' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(total: 3))), GateMode::Ask, false, true, false, GateDecision::Passed],
    'forced' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1))), GateMode::Ask, true, true, false, GateDecision::Forced],
    'forced without interaction' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1))), GateMode::Abort, true, false, false, GateDecision::Forced],
    'continue mode' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(errors: 1))), GateMode::Continue, false, false, false, GateDecision::Continued],
    'abort mode' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1))), GateMode::Abort, false, true, true, GateDecision::Aborted],
    'no interaction' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1))), GateMode::Ask, false, false, true, GateDecision::Aborted],
    'user says yes' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1))), GateMode::Ask, false, true, true, GateDecision::Continued],
    'user says no' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 1))), GateMode::Ask, false, true, false, GateDecision::Aborted],
    'browser green' => fn () => [gateSteps(StepResult::ok(PreflightStep::Browser, new BrowserDTO(total: 2))), GateMode::Ask, false, true, false, GateDecision::Passed],
    'browser fails' => fn () => [gateSteps(StepResult::ok(PreflightStep::Tests, new TestsDTO(total: 3)), StepResult::ok(PreflightStep::Browser, new BrowserDTO(failed: 1))), GateMode::Ask, false, true, false, GateDecision::Aborted],
    'browser fails, user says yes' => fn () => [gateSteps(StepResult::ok(PreflightStep::Browser, new BrowserDTO(errors: 1))), GateMode::Ask, false, true, true, GateDecision::Continued],
]);

it('counts failing tests and browser tests for the gate', function () {
    $results = gateSteps(
        StepResult::ok(PreflightStep::Tests, new TestsDTO(failed: 2, errors: 1)),
        StepResult::ok(PreflightStep::Browser, new BrowserDTO(failed: 1)),
    );

    expect(TestGate::failures($results))->toBe(4);
});

it('round-trips step results through summary.json data', function () {
    $result = StepResult::ok(PreflightStep::Tests, new TestsDTO(total: 5, failed: 2, coveragePct: 71.4, failing: ['A::b']))->withDuration(1.5);

    $restored = StepResult::fromArray(PreflightStep::Tests, $result->toArray());

    expect($restored->data)->toEqual($result->data)
        ->and($restored->duration)->toBe(1.5)
        ->and($restored->data?->failures())->toBe(2);
});
