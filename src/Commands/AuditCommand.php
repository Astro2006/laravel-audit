<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Console\Columns;
use Astro2006\LaravelAudit\Console\LiveOutput;
use Astro2006\LaravelAudit\Console\StepFormatter;
use Astro2006\LaravelAudit\Console\TaskBoard;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Enums\AgentMode;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\CheckStatus;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\GateMode;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\PreflightRunner;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\SummaryBuilder;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Prompts\PromptContextBuilder;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\AuditTasks;
use Astro2006\LaravelAudit\Run\GitInfo;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Astro2006\LaravelAudit\Run\RiskPoints;
use Astro2006\LaravelAudit\Run\RunFinalizer;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Symfony\Component\Console\Terminal;

class AuditCommand extends Command
{
    public const int OK = 0;

    public const int GATE_ABORTED = 2;

    public const int HARNESS_ERROR = 3;

    protected $signature = 'audit
        {--tests : Run the tests audit}
        {--frontend : Run the frontend audit}
        {--backend : Run the backend audit}
        {--dependencies : Run the dependencies audit}
        {--without=* : Leave out audits or steps for this run: tests, frontend, backend, dependencies, browser, phpstan, pint, coverage}
        {--force : Continue even if tests fail}
        {--preflight-only : Run the tools only, without agents}
        {--reuse-preflight= : Reuse the preflight of the latest run, or of the given run ID}
        {--agent= : Agent CLI for this run: claude_code, opencode, codex or auto}
        {--model= : Model for this run, in the format the agent CLI expects}
        {--mode= : single (one task at a time) or parallel (up to agent.concurrency tasks at once); default agent.mode}';

    protected $description = 'Audit this app: a deterministic preflight, then headless agents (Claude Code, OpenCode or Codex) for tests, frontend, backend and dependencies';

    private Messages $messages;

    private StepFormatter $formatter;

    private HarnessFactory $factory;

    private LiveOutput $live;

    private AgentMode $mode;

    /** @var array<string, true> preflight steps that started and have no result yet */
    private array $runningSteps = [];

    public function handle(HarnessFactory $factory): int
    {
        $this->factory = $factory;
        $this->messages = Messages::fromConfig();
        $this->formatter = new StepFormatter($this->messages);
        $this->live = new LiveOutput($this->output->getOutput());

        $project = $factory->project();

        try {
            $selection = RunSelection::resolve(
                array_map('boolval', Read::array($factory->allConfig(), 'audits')),
                $this->explicitAudits(),
                array_values(array_map('strval', (array) $this->option('without'))),
            );
            $driver = $factory->driver($project, $this->stringOption('agent'), $this->stringOption('model'));
            $this->mode = AgentMode::resolve($this->stringOption('mode') ?? (string) $factory->config('agent.mode', AgentMode::Single->value));
        } catch (InvalidSelection $exception) {
            $this->components->error($exception->getMessage());

            return self::HARNESS_ERROR;
        }

        $guard = $factory->databaseGuard($project);
        $preflightOnly = (bool) $this->option('preflight-only');

        $checks = (new RequirementChecker($project, $factory->allConfig(), $guard, $driver, $this->messages))
            ->check($selection, ! $preflightOnly, app()->environment('production'));
        $outdated = $factory->promptRenderer()->outdatedOverrides();

        if ($outdated !== [] && ! $preflightOnly) {
            $checks[] = Check::warning($this->messages->get('requirements.prompts_outdated', ['files' => implode(', ', $outdated)]));
        }

        $this->header($driver, $selection);
        $this->printChecks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return self::HARNESS_ERROR;
        }

        $run = AuditRun::create((string) $factory->config('output_path', storage_path('audit')));
        $manifest = new RunManifest($run, $selection, GitInfo::read($project->basePath), $driver->name(), $this->packageVersion());
        $manifest->write();

        $this->section('preflight');

        $summary = $this->input->hasParameterOption('--reuse-preflight')
            ? $this->reusePreflight($run, $manifest, $selection, $project)
            : $this->runPreflight($run, $manifest, $selection, $project, $guard);

        $this->live->release();

        if ($summary === null) {
            $manifest->finish(RunStatus::Failed);
            $manifest->write();

            return self::HARNESS_ERROR;
        }

        $manifest->gate = $summary->gate;
        $manifest->browser = $this->browserLabel($summary);

        if (! $summary->gate->allowsAgents()) {
            $manifest->finish(RunStatus::Aborted);
            $manifest->write();
            $this->newLine();
            $this->line(' <fg=yellow>'.$this->messages->get('gate.aborted', ['path' => $project->relative($run->preflightDir())]).'</>');
            $this->newLine();

            return self::GATE_ABORTED;
        }

        if ($preflightOnly) {
            $manifest->finish(RunStatus::PreflightOnly);
            $manifest->write();
            $this->newLine();
            $this->line(' '.$this->messages->get('summary.preflight_only', ['path' => $project->relative($run->manifestPath())]));
            $this->newLine();

            return self::OK;
        }

        $outcomes = $this->runAgents($driver, $run, $selection, $project, $summary);

        $riskPoints = new RiskPoints(
            Read::array($factory->allConfig(), 'risk_points.weights'),
            Read::array($factory->allConfig(), 'risk_points.confidence'),
        );
        (new RunFinalizer($riskPoints))->finalize($manifest, $summary, $outcomes, $driver->name());

        return $this->printSummary($manifest, $outcomes, $project);
    }

    /** @return list<string> */
    private function explicitAudits(): array
    {
        return array_values(array_filter(
            AuditType::values(),
            fn (string $audit): bool => (bool) $this->option($audit),
        ));
    }

    private function runPreflight(AuditRun $run, RunManifest $manifest, RunSelection $selection, ProjectInspector $project, DatabaseGuard $guard): PreflightSummary
    {
        $context = new PreflightContext($project, $run->preflightDir(), $run->logsDir(), $this->factory->allConfig(), $selection, $this->factory->childEnvironment($project));

        $gate = (new PreflightRunner($this->factory->steps($project, $guard)))->run(
            $context,
            fn (StepResult $result) => $this->printStep($result),
            fn (StepResult $tests) => $this->gate($tests),
            fn (PreflightStep $step) => $this->startStep($step),
        );

        $summary = (new SummaryBuilder)->write($context, $run->id, $gate);
        $manifest->steps = $context->results;
        $manifest->preflightComplete = $gate->allowsAgents() && ! $context->results->hasErrors();

        return $summary;
    }

    private function reusePreflight(AuditRun $run, RunManifest $manifest, RunSelection $selection, ProjectInspector $project): ?PreflightSummary
    {
        $reuse = new PreflightReuse;
        $value = $this->option('reuse-preflight');
        $source = $reuse->find($run->outputRoot, is_string($value) && $value !== '' ? $value : null, $run->id);

        if ($source === null) {
            $this->components->error($this->messages->get('reuse.not_found', ['path' => $project->relative($run->outputRoot)]));

            return null;
        }

        $reuse->copy($run->outputRoot.'/'.$source->runId.'/preflight', $run->preflightDir());
        $stored = PreflightSummary::fromDirectory($run->preflightDir());
        $missing = $reuse->missingSteps($stored, $selection->requiredSteps());

        if ($missing !== []) {
            $this->components->error($this->messages->get('reuse.incomplete', ['run' => $source->runId, 'steps' => implode(', ', $missing)]));

            return null;
        }

        $this->line('  '.$this->messages->get('reuse.using', ['run' => $source->runId]));

        $now = ($manifest->git->commit ?? '').($manifest->git->dirty ? '+dirty' : '');

        if ($source->codeState() !== $now || str_contains($now, '+dirty')) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get('reuse.commit_changed', ['then' => $source->codeState() ?: '?', 'now' => $now ?: '?']));
        }

        foreach ($stored->steps->all() as $result) {
            if (in_array($result->step, $selection->requiredSteps(), true)) {
                $this->printStep($result);
            }
        }

        $manifest->preflightFrom = $source->runId;
        $manifest->preflightComplete = true;
        $manifest->steps = $stored->steps;

        $tests = $stored->steps->get(PreflightStep::Tests);
        $gate = $tests === null ? GateDecision::NotRun : $this->gate($tests);

        return new PreflightSummary($run->id, $gate, $stored->steps, $run->preflightDir());
    }

    private function gate(StepResult $tests): GateDecision
    {
        $this->live->release();
        $data = TestGate::data($tests);

        if ($data !== null && $data->failures() > 0) {
            $this->newLine();
            $this->line('  <fg=yellow>'.$this->messages->get('gate.failing', ['count' => $data->failures()]).'</>');

            foreach ($data->failing as $name) {
                $this->line('    · '.$name);
            }

            if ($data->environmentError) {
                $this->line('  <fg=red>✘ '.$this->messages->get('gate.broken', ['reason' => $data->topFailure ?? '?']).'</>');
            }
        }

        // Without agents there is nothing to protect; the remaining steps still
        // run. --reuse-preflight asks again before agents start.
        if ($this->option('preflight-only') && $data !== null && $data->failures() > 0) {
            return GateDecision::Continued;
        }

        return (new TestGate)->decide(
            $tests,
            GateMode::tryFrom((string) $this->factory->config('preflight.tests.gate', 'ask')) ?? GateMode::Ask,
            (bool) $this->option('force'),
            $this->input->isInteractive(),
            fn (TestsData $data): bool => $this->confirm($this->messages->get('gate.question'), false),
        );
    }

    /** @return list<AuditOutcome> */
    private function runAgents(AgentDriver $driver, AuditRun $run, RunSelection $selection, ProjectInspector $project, PreflightSummary $summary): array
    {
        $extra = str_starts_with($run->dir(), rtrim($project->basePath, '/').'/') ? [] : [$run->outputRoot];
        $config = $driver->writeConfig($run->logsDir(), $extra);
        $context = (new PromptContextBuilder)->build($summary, $run, $project, $this->factory->allConfig(), $this->messages->locale, $project->boostMcpServer() !== null, $driver->toolsNote());
        $validator = $this->factory->validator($project);

        $tasks = new AuditTasks(
            $selection->audits,
            $summary,
            $context,
            $this->factory->promptRenderer(),
            $validator,
            $run,
            $project->basePath,
            $config,
            $extra,
            max(1, (int) $this->factory->config('max_findings_per_task', 8)),
        );

        $this->section('agents');

        $runner = new AgentRunner(
            $driver,
            $validator,
            $run->logsDir(),
            $this->concurrency(),
            (int) $this->factory->config('agent.timeout', 900),
            environment: $this->factory->childEnvironment($project),
        );

        $jobs = $tasks->jobs();
        $board = new TaskBoard($jobs, $this->messages, microtime(true));
        $width = (new Terminal)->getWidth();
        $lastDrawn = 0.0;
        $draw = function (bool $force = false) use ($board, $width, &$lastDrawn): void {
            $now = microtime(true);

            if ($force || $now - $lastDrawn >= 1.0) {
                $lastDrawn = $now;
                $this->live->show($board->live($now, $width));
            }
        };

        foreach ($board->plan() as $line) {
            $this->live->log($line);
        }

        $draw(true);

        $outcomes = $tasks->outcomes($runner->run(
            $jobs,
            function (AgentJob $job) use ($board, $draw): void {
                $board->started($job, microtime(true));

                if (! $this->live->enabled()) {
                    $this->live->log('  <fg=gray>▶ '.$this->messages->get('board.started', ['task' => $job->key]).'</>');
                }

                $draw(true);
            },
            function (TaskOutcome $outcome) use ($board, $draw): void {
                $board->finished($outcome);
                $this->printTask($outcome);
                $draw(true);
            },
            fn () => $draw(),
            function (AgentJob $job) use ($board, $draw): void {
                $board->repairing($job);
                $draw(true);
            },
        ));

        $this->live->release();
        $this->newLine();

        foreach ($outcomes as $outcome) {
            $this->printOutcome($outcome);
        }

        return $outcomes;
    }

    /** @param list<AuditOutcome> $outcomes */
    private function printSummary(RunManifest $manifest, array $outcomes, ProjectInspector $project): int
    {
        $findings = [];
        $failed = [];

        foreach ($outcomes as $outcome) {
            array_push($findings, ...$outcome->findings);

            if (! $outcome->completed()) {
                $failed[] = $outcome->audit->value.($outcome->status === AuditStatus::Partial ? ' ('.$outcome->status->value.')' : '');
            }
        }

        $this->newLine();
        $this->line(' '.$this->messages->get('summary.findings', RunManifest::severityCounts($findings))
            .($manifest->overlaps === [] ? '' : ' · '.$this->messages->get('summary.duplicates', ['count' => count($manifest->overlaps)])));

        if ($failed !== []) {
            $this->line(' <fg=red>'.$this->messages->get('summary.failed_audits', ['audits' => implode(', ', $failed)]).'</>');
        }

        $this->line(' '.$this->messages->get('summary.report', ['path' => $project->relative($manifest->run->manifestPath())]));
        $this->newLine();

        return $failed === [] ? self::OK : self::HARNESS_ERROR;
    }

    private function header(AgentDriver $driver, RunSelection $selection): void
    {
        $model = $driver->model();

        $this->newLine();
        $this->line(' <options=bold>'.$this->messages->get('header', [
            'version' => $this->packageVersion(),
            'agent' => $driver->displayName().($model !== null ? ' ('.$model.')' : ''),
            'mode' => $this->messages->get('modes.'.$this->mode->value, ['count' => $this->concurrency()]),
        ]).'</>');

        if ($selection->without !== [] || count($selection->audits) < count(AuditType::cases())) {
            $without = $selection->without === [] ? '' : ' · '.$this->messages->get('without', ['items' => implode(', ', $selection->withoutValues())]);
            $this->line(' <fg=gray>'.implode(', ', $selection->auditValues()).$without.'</>');
        }

        $this->section('requirements');
    }

    /** @param list<Check> $checks */
    private function printChecks(array $checks): void
    {
        foreach ($checks as $check) {
            $color = match ($check->status) {
                CheckStatus::Ok => 'green',
                CheckStatus::Info => 'blue',
                CheckStatus::Warning => 'yellow',
                CheckStatus::Failed => 'red',
            };

            $this->line(sprintf('  <fg=%s>%s</> %s', $color, $check->status->symbol(), $check->message));
        }
    }

    private function concurrency(): int
    {
        return $this->mode->concurrency((int) $this->factory->config('agent.concurrency', 4));
    }

    /** Shows the step as running until its result arrives. */
    private function startStep(PreflightStep $step): void
    {
        $this->runningSteps[$step->value] = true;
        $this->showRunningSteps();
    }

    private function showRunningSteps(): void
    {
        $width = (new Terminal)->getWidth();

        $this->live->show(array_map(
            fn (string $step): string => Columns::detail('<fg=yellow>●</> '.$this->formatter->label(PreflightStep::from($step)), '<fg=yellow>'.$this->messages->get('agents.running').'</>', $width),
            array_keys($this->runningSteps),
        ));
    }

    private function printStep(StepResult $result): void
    {
        unset($this->runningSteps[$result->step->value]);
        $this->showRunningSteps();

        $line = $this->formatter->format($result);
        $this->live->log(Columns::detail(sprintf('<fg=%s>%s</> %s', $line->color, $line->symbol, $line->label), $line->detail, (new Terminal)->getWidth()));

        foreach ($this->formatter->warnings($result) as $warning) {
            $this->live->log('    <fg=yellow>⚠</> '.$warning);
        }
    }

    private function printTask(TaskOutcome $outcome): void
    {
        $detail = $outcome->completed()
            ? $this->messages->get('agents.task_finished', ['findings' => count($outcome->findings), 'duration' => TaskBoard::duration($outcome->finishedAt - $outcome->startedAt)])
            : $this->messages->get('agents.failed', ['reason' => mb_strimwidth((string) $outcome->reason, 0, 70, '…')]);

        $this->live->log(sprintf('  <fg=%s>%s</> <fg=gray>%s · %s</>', $outcome->completed() ? 'green' : 'red', $outcome->completed() ? '✔' : '✘', $outcome->key, $detail));
    }

    private function printOutcome(AuditOutcome $outcome): void
    {
        $name = $outcome->audit->value;

        if ($outcome->status === AuditStatus::Failed) {
            $this->components->twoColumnDetail('<fg=red>✘</> '.$name, $this->messages->get('agents.failed', ['reason' => mb_strimwidth((string) $outcome->reason, 0, 90, '…')]));

            return;
        }

        $t = $outcome->telemetry;
        $seconds = intdiv($t->durationMs, 1000);
        $detail = $this->messages->get('agents.finished', [
            'findings' => count($outcome->findings),
            'turns' => $t->turns,
            'input' => $this->tokens($t->inputTokens + $t->cacheReadTokens),
            'output' => $this->tokens($t->outputTokens),
            'duration' => sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60),
        ]).($outcome->repaired ? ' · '.$this->messages->get('agents.repaired') : '');

        if ($outcome->status === AuditStatus::Partial) {
            $this->components->twoColumnDetail('<fg=yellow>⚠</> '.$name, $detail.' · '.mb_strimwidth((string) $outcome->reason, 0, 60, '…'));

            return;
        }

        $this->components->twoColumnDetail('<fg=green>✔</> '.$name, $detail);
    }

    private function section(string $key): void
    {
        $this->live->release();
        $this->newLine();
        $this->line(' <options=bold>'.$this->messages->get('sections.'.$key).'</>');
    }

    private function browserLabel(PreflightSummary $summary): string
    {
        $result = $summary->steps->get(PreflightStep::Browser);

        return match ($result?->status) {
            StepStatus::Ok => 'playwright',
            StepStatus::Skipped => 'skipped ('.$result->reason?->value.')',
            StepStatus::Error => 'error',
            null => 'not part of this run',
        };
    }

    private function tokens(int $tokens): string
    {
        return match (true) {
            $tokens >= 1_000_000 => number_format($tokens / 1_000_000, 1).' M',
            $tokens >= 1_000 => number_format($tokens / 1_000).' k',
            default => (string) $tokens,
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function packageVersion(): string
    {
        try {
            return InstalledVersions::isInstalled('astro2006/laravel-audit')
                ? (string) InstalledVersions::getPrettyVersion('astro2006/laravel-audit')
                : 'dev';
        } catch (\Throwable) {
            return 'dev';
        }
    }
}
