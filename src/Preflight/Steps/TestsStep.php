<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\AsyncStep;
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
use Illuminate\Support\Facades\Process;

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

        if ($project->phpunitConfigPath() === null && ! is_dir($project->path('tests'))) {
            return StepResult::skipped($this->step(), SkipReason::NoConfig);
        }

        $this->database = $this->guard->check();

        if (! $this->database->safe && ! $context->config('preflight.allow_shared_database', false)) {
            return StepResult::skipped($this->step(), SkipReason::SharedDatabase, ['database' => $this->database->toArray()]);
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

        return Process::path($project->basePath)
            ->timeout((int) $context->config('preflight.tests.timeout', 1800))
            ->env($driver === 'xdebug' && $this->coverage ? ['XDEBUG_MODE' => 'coverage'] : [])
            ->start($command);
    }

    public function finish(PreflightContext $context, ProcessResult $result): StepResult
    {
        file_put_contents($context->logPath('tests.log'), $result->output()."\n".$result->errorOutput());

        $condensed = $this->junit->condense($context->path('tests/junit.xml'), $context->project->basePath);

        if ($condensed === null) {
            return StepResult::error(
                $this->step(),
                'The test run produced no JUnit report (see logs/tests.log): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8),
                ['database' => $this->database?->toArray()],
            );
        }

        $context->writeJson('tests/tests-condensed.json', $condensed);

        $coverage = $this->coverage
            ? $this->coverageCondenser->condense($context->path('tests/coverage.xml'), $context->project->basePath)
            : null;

        if ($coverage !== null) {
            $context->writeJson('tests/coverage-by-class.json', $coverage);
        }

        $this->writeRouteTestMap($context);

        return StepResult::ok($this->step(), [
            'total' => $condensed['total'],
            'passed' => $condensed['passed'],
            'failed' => $condensed['failed'],
            'errors' => $condensed['errors'],
            'skipped' => $condensed['skipped'],
            'duration_s' => $condensed['duration_s'],
            'environment_error' => $condensed['environment_error'],
            'top_failure' => isset($condensed['failure_groups'][0])
                ? $condensed['failure_groups'][0]['exception'].': '.$condensed['failure_groups'][0]['message']
                : null,
            'failing' => array_merge(...array_map(fn (array $group): array => $group['tests'], array_slice($condensed['failure_groups'], 0, 5))),
            'coverage_available' => $coverage !== null,
            'coverage_pct' => $coverage['lines_pct'] ?? null,
            'parallel' => $this->parallel,
            'suites' => $this->suites === [] ? null : $this->suites,
            'database' => $this->database?->toArray(),
            'file' => 'tests/tests-condensed.json',
        ]);
    }

    /**
     * Every configured suite except the browser suite, which runs in its own
     * step. Empty when there is no browser suite (= run the default set).
     *
     * @return list<string>
     */
    private function suitesWithoutBrowser(PreflightContext $context): array
    {
        $suites = $context->project->testSuites();
        $browser = (string) $context->config('preflight.browser.testsuite', 'Browser');

        if (! in_array($browser, $suites, true)) {
            return [];
        }

        return array_values(array_filter($suites, fn (string $suite): bool => $suite !== $browser));
    }

    private function writeRouteTestMap(PreflightContext $context): void
    {
        $routes = $context->readJson('routes.json');

        if ($routes === []) {
            return;
        }

        $files = [];

        foreach ($context->project->phpFiles('tests') as $file) {
            $files[$file] = (string) file_get_contents($context->project->path($file));
        }

        $context->writeJson('tests/route-test-map.json', $this->mapper->map(array_values($routes), $files));
    }
}
