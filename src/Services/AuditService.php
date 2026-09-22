<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Services;

use Astro2006\LaravelAudit\Actions\Audit\ApplyTestGate;
use Astro2006\LaravelAudit\Actions\Audit\CheckRequirements;
use Astro2006\LaravelAudit\Actions\Audit\ReusePreflight;
use Astro2006\LaravelAudit\Actions\Audit\RunAgents;
use Astro2006\LaravelAudit\Actions\Audit\RunPreflight;
use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\AgentMode;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\SummaryBuilder;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\GitInfo;
use Astro2006\LaravelAudit\Run\RiskPoints;
use Astro2006\LaravelAudit\Run\RunFinalizer;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Composer\InstalledVersions;
use Throwable;

/**
 * One audit run from start to end: requirements, preflight, test gate,
 * agents, report. Each phase is an action; this class only decides what
 * comes next and which exit code the run ends with.
 */
final class AuditService
{
    public const int OK = 0;

    public const int GATE_ABORTED = 2;

    public const int HARNESS_ERROR = 3;

    public function __construct(
        private readonly HarnessFactory $factory,
        private readonly CheckRequirements $checkRequirements,
        private readonly RunPreflight $runPreflight,
        private readonly ReusePreflight $reusePreflight,
        private readonly ApplyTestGate $applyTestGate,
        private readonly RunAgents $runAgents,
    ) {}

    public function run(AuditOptions $options, AuditConsole $console): int
    {
        $project = $this->factory->project();

        try {
            $selection = RunSelection::resolve(Read::array($this->factory->allConfig(), 'audits'), $options->audits, $options->without);
            $driver = $this->factory->driver($project, $options->agent, $options->model);
            $mode = AgentMode::resolve($options->mode ?? (string) $this->factory->config('agent.mode', AgentMode::Single->value));
        } catch (InvalidSelection $exception) {
            $console->error($exception->getMessage());

            return self::HARNESS_ERROR;
        }

        $concurrency = $mode->concurrency((int) $this->factory->config('agent.concurrency', 4));
        $guard = $this->factory->databaseGuard($project);
        $checks = $this->checkRequirements->handle($project, $selection, $guard, $driver, $console->messages, ! $options->preflightOnly);

        $console->header(self::version(), $driver, $console->messages->get('modes.'.$mode->value, ['count' => $concurrency]), $selection);
        $console->checks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return self::HARNESS_ERROR;
        }

        $run = AuditRun::create((string) $this->factory->config('output_path', storage_path('audit')));
        $manifest = new RunManifest($run, $selection, GitInfo::read($project->basePath), $driver->name(), self::version());
        $manifest->write();

        $console->section('preflight');
        $summary = $this->preflight($options, $run, $manifest, $selection, $project, $guard, $console);

        if ($summary === null) {
            $manifest->finish(RunStatus::Failed);
            $manifest->write();

            return self::HARNESS_ERROR;
        }

        $manifest->gate = $summary->gate;
        $manifest->browser = self::browserLabel($summary);

        if (! $summary->gate->allowsAgents()) {
            $manifest->finish(RunStatus::Aborted);
            $manifest->write();
            $console->closing('<fg=yellow>'.$console->messages->get('gate.aborted', ['path' => $project->relative($run->preflightDir())]).'</>');

            return self::GATE_ABORTED;
        }

        if ($options->preflightOnly) {
            $manifest->finish(RunStatus::PreflightOnly);
            $manifest->write();
            $console->closing($console->messages->get('summary.preflight_only', ['path' => $project->relative($run->manifestPath())]));

            return self::OK;
        }

        $outcomes = $this->runAgents->handle($driver, $run, $selection, $project, $summary, $manifest, $concurrency, $console);

        (new RunFinalizer($this->riskPoints()))->finalize($manifest, $summary, $outcomes, $driver->name());
        $console->summary($manifest, $outcomes, $project->relative($manifest->run->manifestPath()));

        foreach ($outcomes as $outcome) {
            if (! $outcome->completed()) {
                return self::HARNESS_ERROR;
            }
        }

        return self::OK;
    }

    /**
     * Runs or reuses the preflight, then asks the test gate once every step
     * has a result.
     */
    private function preflight(AuditOptions $options, AuditRun $run, RunManifest $manifest, RunSelection $selection, ProjectInspector $project, DatabaseGuard $guard, AuditConsole $console): ?PreflightSummary
    {
        if ($options->reusePreflight) {
            $steps = $this->reusePreflight->handle($run, $manifest, $selection, $project, $options->reuseRunId, $console);
            $console->live->release();

            if ($steps === null) {
                return null;
            }

            $manifest->steps = $steps;
            $manifest->preflightComplete = true;

            return new PreflightSummary($run->id, $this->applyTestGate->handle($steps, $options, $console), $steps, $run->preflightDir());
        }

        $context = $this->runPreflight->handle($run, $selection, $project, $guard, $console);
        $console->live->release();

        $manifest->steps = $context->results;
        $manifest->preflightComplete = ! $context->results->hasErrors();

        return (new SummaryBuilder)->write($context, $run->id, $this->applyTestGate->handle($context->results, $options, $console));
    }

    private function riskPoints(): RiskPoints
    {
        return new RiskPoints(
            Read::array($this->factory->allConfig(), 'risk_points.weights'),
            Read::array($this->factory->allConfig(), 'risk_points.confidence'),
        );
    }

    private static function browserLabel(PreflightSummary $summary): string
    {
        $result = $summary->steps->get(PreflightStep::Browser);

        return match ($result?->status) {
            StepStatus::Ok => 'playwright',
            StepStatus::Skipped => 'skipped ('.$result->reason?->value.')',
            StepStatus::Error => 'error',
            null => 'not part of this run',
        };
    }

    private static function version(): string
    {
        try {
            return InstalledVersions::isInstalled('astro2006/laravel-audit')
                ? (string) InstalledVersions::getPrettyVersion('astro2006/laravel-audit')
                : 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
