<?php

use Astro2006\LaravelAudit\Contracts\Step;
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

function fakeStep(PreflightStep $step, array $data = [], ?Closure $run = null): Step
{
    return new class($step, $data, $run) implements Step
    {
        public function __construct(private PreflightStep $step, private array $data, private ?Closure $callback) {}

        public function step(): PreflightStep
        {
            return $this->step;
        }

        public function run(PreflightContext $context): StepResult
        {
            return $this->callback ? ($this->callback)() : StepResult::ok($this->step, $this->data);
        }
    };
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
    $steps = array_map(fn (PreflightStep $step) => fakeStep($step, ['failed' => 0]), PreflightStep::cases());

    $decision = (new PreflightRunner($steps))->run(
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
    $steps = array_map(fn (PreflightStep $step) => fakeStep($step, ['failed' => 3]), PreflightStep::cases());
    $context = runnerContext();

    $decision = (new PreflightRunner($steps))->run($context, fn () => null, fn () => GateDecision::Aborted);

    expect($decision)->toBe(GateDecision::Aborted)
        ->and($context->result(PreflightStep::Browser)->reason)->toBe(SkipReason::GateAborted)
        ->and($context->result(PreflightStep::Phpstan)->reason)->toBe(SkipReason::GateAborted);
});

it('runs only the steps the selected audits need', function () {
    $context = runnerContext(['dependencies']);
    $steps = array_map(fn (PreflightStep $step) => fakeStep($step), PreflightStep::cases());

    (new PreflightRunner($steps))->run($context, fn () => null, fn () => GateDecision::NotRun);

    expect(array_keys($context->results()))->toBe(['about', 'stack', 'loc', 'composer']);
});

it('turns a crashing step into an error result', function () {
    $context = runnerContext(['dependencies']);
    $steps = [
        fakeStep(PreflightStep::About),
        fakeStep(PreflightStep::Composer, run: fn () => throw new RuntimeException('boom')),
    ];

    (new PreflightRunner($steps))->run($context, fn () => null, fn () => GateDecision::NotRun);

    expect($context->result(PreflightStep::Composer)->error)->toBe('RuntimeException: boom');
});

it('decides the test gate', function (?StepResult $tests, GateMode $mode, bool $force, bool $interactive, bool $answer, GateDecision $expected) {
    expect((new TestGate)->decide($tests, $mode, $force, $interactive, fn () => $answer))->toBe($expected);
})->with([
    'no tests' => [null, GateMode::Ask, false, true, false, GateDecision::NotRun],
    'green' => [StepResult::ok(PreflightStep::Tests, ['failed' => 0]), GateMode::Ask, false, true, false, GateDecision::Passed],
    'forced' => [StepResult::ok(PreflightStep::Tests, ['failed' => 1]), GateMode::Ask, true, false, false, GateDecision::Forced],
    'continue mode' => [StepResult::ok(PreflightStep::Tests, ['errors' => 1]), GateMode::Continue, false, false, false, GateDecision::Continued],
    'abort mode' => [StepResult::ok(PreflightStep::Tests, ['failed' => 1]), GateMode::Abort, false, true, true, GateDecision::Aborted],
    'no interaction' => [StepResult::ok(PreflightStep::Tests, ['failed' => 1]), GateMode::Ask, false, false, true, GateDecision::Aborted],
    'user says yes' => [StepResult::ok(PreflightStep::Tests, ['failed' => 1]), GateMode::Ask, false, true, true, GateDecision::Continued],
    'user says no' => [StepResult::ok(PreflightStep::Tests, ['failed' => 1]), GateMode::Ask, false, true, false, GateDecision::Aborted],
]);
