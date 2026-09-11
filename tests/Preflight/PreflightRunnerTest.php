<?php

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Contracts\StepData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\PreflightRunner;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function fakeStep(PreflightStep $step, ?StepData $data = null, ?Closure $run = null): Step
{
    return new class($step, $data, $run) implements Step
    {
        public function __construct(private PreflightStep $step, private ?StepData $data, private ?Closure $callback) {}

        public function step(): PreflightStep
        {
            return $this->step;
        }

        public function run(PreflightContext $context): StepResult
        {
            if ($this->callback !== null) {
                return ($this->callback)();
            }

            $class = $this->step->dataClass();

            return StepResult::ok($this->step, $this->data ?? new $class);
        }
    };
}

/** @return list<Step> */
function fakeSteps(?TestsData $tests = null): array
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
        [],
        RunSelection::resolve(['tests' => true, 'frontend' => true, 'backend' => true, 'dependencies' => true], $explicit, []),
    );
}

it('runs tests first (async in real runs), light steps, then gate, browser and phpstan', function () {
    $order = [];

    $decision = (new PreflightRunner(fakeSteps()))->run(
        runnerContext(),
        function (StepResult $result) use (&$order) {
            $order[] = $result->step->value;
        },
        fn (StepResult $tests) => GateDecision::Passed,
    );

    expect($decision)->toBe(GateDecision::Passed)
        ->and($order)->toBe(['tests', 'about', 'stack', 'loc', 'pint', 'routes', 'translations', 'composer', 'browser', 'phpstan']);
});

it('skips heavy steps after an aborted gate', function () {
    $context = runnerContext();

    $decision = (new PreflightRunner(fakeSteps(new TestsData(failed: 3))))->run($context, fn () => null, fn () => GateDecision::Aborted);

    expect($decision)->toBe(GateDecision::Aborted)
        ->and($context->results->get(PreflightStep::Browser)->reason)->toBe(SkipReason::GateAborted)
        ->and($context->results->get(PreflightStep::Phpstan)->reason)->toBe(SkipReason::GateAborted);
});

it('runs only the steps the selected audits need', function () {
    $context = runnerContext(['dependencies']);

    (new PreflightRunner(fakeSteps()))->run($context, fn () => null, fn () => GateDecision::NotRun);

    expect($context->results->steps())->toBe([PreflightStep::About, PreflightStep::Stack, PreflightStep::LinesOfCode, PreflightStep::Composer]);
});

it('turns a crashing step into an error result', function () {
    $context = runnerContext(['dependencies']);
    $steps = [
        fakeStep(PreflightStep::About),
        fakeStep(PreflightStep::Composer, run: fn () => throw new RuntimeException('boom')),
    ];

    (new PreflightRunner($steps))->run($context, fn () => null, fn () => GateDecision::NotRun);

    expect($context->results->get(PreflightStep::Composer)->error)->toBe('RuntimeException: boom')
        ->and($context->results->hasErrors())->toBeTrue();
});

it('decides the test gate', function (?StepResult $tests, GateMode $mode, bool $force, bool $interactive, bool $answer, GateDecision $expected) {
    expect((new TestGate)->decide($tests, $mode, $force, $interactive, fn () => $answer))->toBe($expected);
})->with([
    'no tests' => [null, GateMode::Ask, false, true, false, GateDecision::NotRun],
    'skipped' => [StepResult::skipped(PreflightStep::Tests, SkipReason::SharedDatabase), GateMode::Ask, false, true, false, GateDecision::NotRun],
    'green' => [StepResult::ok(PreflightStep::Tests, new TestsData(total: 3)), GateMode::Ask, false, true, false, GateDecision::Passed],
    'forced' => [StepResult::ok(PreflightStep::Tests, new TestsData(failed: 1)), GateMode::Ask, true, false, false, GateDecision::Forced],
    'continue mode' => [StepResult::ok(PreflightStep::Tests, new TestsData(errors: 1)), GateMode::Continue, false, false, false, GateDecision::Continued],
    'abort mode' => [StepResult::ok(PreflightStep::Tests, new TestsData(failed: 1)), GateMode::Abort, false, true, true, GateDecision::Aborted],
    'no interaction' => [StepResult::ok(PreflightStep::Tests, new TestsData(failed: 1)), GateMode::Ask, false, false, true, GateDecision::Aborted],
    'user says yes' => [StepResult::ok(PreflightStep::Tests, new TestsData(failed: 1)), GateMode::Ask, false, true, true, GateDecision::Continued],
    'user says no' => [StepResult::ok(PreflightStep::Tests, new TestsData(failed: 1)), GateMode::Ask, false, true, false, GateDecision::Aborted],
]);

it('round-trips step results through summary.json data', function () {
    $result = StepResult::ok(PreflightStep::Tests, new TestsData(total: 5, failed: 2, coveragePct: 71.4, failing: ['A::b']))->withDuration(1.5);

    $restored = StepResult::fromArray(PreflightStep::Tests, $result->toArray());

    expect($restored->data)->toEqual($result->data)
        ->and($restored->duration)->toBe(1.5)
        ->and(TestGate::data($restored)?->failures())->toBe(2);
});
