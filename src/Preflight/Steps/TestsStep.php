<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\AsyncStep;
use Astro2006\LaravelAudit\Data\Routes\RouteDefinition;
use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\CoverageCondenser;
use Astro2006\LaravelAudit\Preflight\Condensers\JunitCondenser;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteTestMapper;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\DatabaseGuardResult;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;

final class TestsStep implements AsyncStep
{
    private ?DatabaseGuardResult $database = null;

    private bool $coverage = false;

    private bool $parallel = false;

    /** @var list<string> */
    private array $suites = [];

    public function __construct(
        private readonly DatabaseGuard $guard,
        private readonly JunitCondenser $junit = new JunitCondenser,
        private readonly CoverageCondenser $coverageCondenser = new CoverageCondenser,
        private readonly RouteTestMapper $mapper = new RouteTestMapper,
    ) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Tests;
    }

    public function run(PreflightContext $context): StepResult
    {
        $started = $this->start($context);

        return $started instanceof StepResult ? $started : $this->finish($context, $started->wait());
    }

    public function start(PreflightContext $context): InvokedProcess|StepResult
    {
        $project = $context->project;

        if ($project->phpunit()->path() === null && ! is_dir($project->path('tests'))) {
            return StepResult::skipped($this->step(), SkipReason::NoConfig);
        }

        $this->database = $this->guard->check();

        if (! $this->database->safe && ! $context->config('preflight.allow_shared_database', false)) {
            return StepResult::skipped($this->step(), SkipReason::SharedDatabase, new TestsDTO(database: $this->database));
        }

        $junit = $context->path('tests/junit.xml');
        $clover = $context->path('tests/coverage.xml');
        @unlink($junit);
        @unlink($clover);

        $driver = $project->coverageDriver();
        $this->coverage = (bool) $context->config('preflight.tests.coverage', true)
            && ! $context->selection->skips(OptionalStep::Coverage)
            && $driver !== null;
        $this->parallel = (bool) $context->config('preflight.tests.parallel', true) && $project->canRunParallel();
        $this->suites = $this->suitesWithoutBrowser($context);

        $flags = ['--log-junit='.$junit];

        if ($this->coverage) {
            $flags[] = '--coverage-clover='.$clover;
        }

        if ($this->suites !== []) {
            $flags[] = '--testsuite='.implode(',', $this->suites);
        }

        $custom = $context->config('preflight.tests.command');
        $command = is_string($custom) && $custom !== ''
            ? $custom.' '.implode(' ', array_map('escapeshellarg', $flags))
            : $context->artisan(['test', ...($this->parallel ? ['--parallel'] : []), ...$flags]);

        $environment = match (true) {
            ! $this->coverage => [],
            $driver === 'herd' => ['PHPRC' => (string) $project->herdCoverageIni(), 'XDEBUG_MODE' => 'coverage'],
            $driver === 'xdebug' => ['XDEBUG_MODE' => 'coverage'],
            default => [],
        };

        return $context->process($environment)
            ->timeout((int) $context->config('preflight.tests.timeout', 1800))
            ->start($command);
    }

    public function finish(PreflightContext $context, ProcessResult $result): StepResult
    {
        file_put_contents($context->logPath('tests.log'), $result->output()."\n".$result->errorOutput());

        $report = $this->junit->condense($context->path('tests/junit.xml'), $context->project->basePath);

        if ($report === null) {
            return StepResult::error(
                $this->step(),
                'The test run produced no JUnit report (see logs/tests.log): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8),
                new TestsDTO(database: $this->database),
            );
        }

        $context->writeJson('tests/tests-condensed.json', $report);

        $coverage = $this->coverage
            ? $this->coverageCondenser->condense($context->path('tests/coverage.xml'), $context->project->basePath)
            : null;

        if ($coverage !== null) {
            $context->writeJson('tests/coverage-by-class.json', $coverage);
        }

        $this->writeRouteTestMap($context);

        return StepResult::ok($this->step(), TestsDTO::fromReport($report, $coverage?->linesPct, $this->parallel, $this->suites, $this->database));
    }

    /**
     * Every configured suite except the browser suite, which runs in its own
     * step. Empty when there is no browser suite (= run the default set).
     *
     * @return list<string>
     */
    private function suitesWithoutBrowser(PreflightContext $context): array
    {
        $suites = $context->project->phpunit()->testSuites();
        $browser = (string) $context->config('preflight.browser.testsuite', 'Browser');

        if (! in_array($browser, $suites, true)) {
            return [];
        }

        return array_values(array_filter($suites, fn (string $suite): bool => $suite !== $browser));
    }

    private function writeRouteTestMap(PreflightContext $context): void
    {
        $routes = RouteDefinition::listFromJson($context->readJson('routes.json'));

        if ($routes === []) {
            return;
        }

        $files = (function () use ($context): \Generator {
            foreach ($context->project->phpFiles('tests') as $file) {
                yield $file => (string) file_get_contents($context->project->path($file));
            }
        })();

        $context->writeJson('tests/route-test-map.json', $this->mapper->map($routes, $files));
    }
}
