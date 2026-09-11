<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentRunner;
use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Agents\McpConfigWriter;
use Astro2006\LaravelAudit\Console\StepFormatter;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
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
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\GitInfo;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Astro2006\LaravelAudit\Run\RiskPoints;
use Astro2006\LaravelAudit\Run\RunFinalizer;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Composer\InstalledVersions;
use Illuminate\Console\Command;

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
        {--reuse-preflight= : Reuse the preflight of the latest run, or of the given run ID}';

    protected $description = 'Audit this app: a deterministic preflight, then Claude Code agents for tests, frontend, backend and dependencies';

    private Messages $messages;

    private StepFormatter $formatter;

    /** @var array<string, mixed> */
    private array $config = [];

    public function handle(HarnessFactory $factory): int
    {
        $this->messages = Messages::fromConfig();
        $this->formatter = new StepFormatter($this->messages);
        $this->config = $config = $factory->allConfig();

        try {
            $selection = RunSelection::resolve((array) ($config['audits'] ?? []), $this->explicitAudits(), array_values((array) $this->option('without')));
        } catch (InvalidSelection $exception) {
            $this->components->error($exception->getMessage());

            return self::HARNESS_ERROR;
        }

        $project = $factory->project();
        $guard = $factory->databaseGuard($project);
        $driver = $factory->driver($project);
        $preflightOnly = (bool) $this->option('preflight-only');

        $checks = (new RequirementChecker($project, $config, $guard, $driver, $this->messages))
            ->check($selection, ! $preflightOnly, app()->environment('production'));

        $this->header($driver, $selection, $config);
        $this->printChecks($checks);

        if (RequirementChecker::hasFailures($checks)) {
            return self::HARNESS_ERROR;
        }

        $run = AuditRun::create((string) ($config['output_path'] ?? storage_path('audit')));
        $manifest = new RunManifest($run, $selection, GitInfo::read($project->basePath), $driver->name(), $this->packageVersion());
        $manifest->write();

        $this->section('preflight');

        $preflight = $this->input->hasParameterOption('--reuse-preflight')
            ? $this->reusePreflight($run, $manifest, $selection, $project)
            : $this->runPreflight($factory, $run, $manifest, $selection, $project, $guard, $config);

        if ($preflight === null) {
            $manifest->finish(RunStatus::Failed);
            $manifest->write();

            return self::HARNESS_ERROR;
        }

        [$summary, $gate] = $preflight;
        $manifest->gate = $gate;
        $manifest->browser = $this->browserLabel($summary);

        if (! $gate->allowsAgents()) {
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

        $outcomes = $this->runAgents($factory, $driver, $run, $selection, $project, $summary, $config);

        $riskPoints = new RiskPoints(
            (array) ($config['risk_points']['weights'] ?? []),
            (array) ($config['risk_points']['confidence'] ?? []),
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

    /**
     * @param  array<string, mixed>  $config
     * @return array{PreflightSummary, GateDecision}
     */
    private function runPreflight(HarnessFactory $factory, AuditRun $run, RunManifest $manifest, RunSelection $selection, ProjectInspector $project, DatabaseGuard $guard, array $config): array
    {
        $context = new PreflightContext($project, $run->preflightDir(), $run->logsDir(), $config, $selection);
        $runner = new PreflightRunner($factory->steps($project, $guard));

        $gate = $runner->run(
            $context,
            fn (StepResult $result) => $this->printStep($result),
            fn (StepResult $tests) => $this->gate($tests),
        );

        $summary = (new SummaryBuilder)->write($context, $run->id, $gate);
        $manifest->recordSteps($context->results());
        $manifest->preflightComplete = $gate->allowsAgents()
            && ! in_array(StepStatus::Error, array_map(fn (StepResult $result) => $result->status, $context->results()), true);

        return [$summary, $gate];
    }

    /** @return array{PreflightSummary, GateDecision}|null */
    private function reusePreflight(AuditRun $run, RunManifest $manifest, RunSelection $selection, ProjectInspector $project): ?array
    {
        $reuse = new PreflightReuse;
        $value = $this->option('reuse-preflight');
        $source = $reuse->find($run->outputRoot, is_string($value) && $value !== '' ? $value : null, $run->id);

        if ($source === null) {
            $this->components->error($this->messages->get('reuse.not_found', ['path' => $project->relative($run->outputRoot)]));

            return null;
        }

        $reuse->copy($run->outputRoot.'/'.$source['run'].'/preflight', $run->preflightDir());
        $summary = PreflightSummary::fromDirectory($run->preflightDir());
        $missing = $reuse->missingSteps($summary, $selection->requiredSteps());

        if ($missing !== []) {
            $this->components->error($this->messages->get('reuse.incomplete', ['run' => $source['run'], 'steps' => implode(', ', $missing)]));

            return null;
        }

        $this->line('  '.$this->messages->get('reuse.using', ['run' => $source['run']]));

        $then = ($source['manifest']['git_commit'] ?? '').(($source['manifest']['git_dirty'] ?? '') === 'true' ? '+dirty' : '');
        $now = ($manifest->git->commit ?? '').($manifest->git->dirty ? '+dirty' : '');

        if ($then !== $now || str_contains($now, '+dirty')) {
            $this->line('  <fg=yellow>⚠</> '.$this->messages->get('reuse.commit_changed', ['then' => $then ?: '?', 'now' => $now ?: '?']));
        }

        $results = [];

        foreach ($selection->requiredSteps() as $step) {
            $results[$step->value] = StepResult::fromArray($step, $summary->step($step));
            $this->printStep($results[$step->value]);
        }

        $manifest->preflightFrom = $source['run'];
        $manifest->preflightComplete = true;
        $manifest->recordSteps($results);

        $gate = isset($results[PreflightStep::Tests->value])
            ? $this->gate($results[PreflightStep::Tests->value])
            : GateDecision::NotRun;

        return [$summary, $gate];
    }

    private function gate(StepResult $tests): GateDecision
    {
        if ($tests->isOk() && TestGate::failures($tests) > 0) {
            $this->newLine();
            $this->line('  <fg=yellow>'.$this->messages->get('gate.failing', ['count' => TestGate::failures($tests)]).'</>');

            foreach (array_slice((array) $tests->get('failing', []), 0, 5) as $name) {
                $this->line('    · '.$name);
            }

            if ($tests->get('environment_error')) {
                $this->line('  <fg=red>✘ '.$this->messages->get('gate.broken', ['reason' => (string) $tests->get('top_failure', '?')]).'</>');
            }
        }

        $mode = GateMode::tryFrom((string) ($this->config['preflight']['tests']['gate'] ?? 'ask')) ?? GateMode::Ask;

        return (new TestGate)->decide(
            $tests,
            $mode,
            (bool) $this->option('force'),
            $this->input->isInteractive(),
            fn () => $this->confirm($this->messages->get('gate.question'), false),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, AuditOutcome>
     */
    private function runAgents(HarnessFactory $factory, AgentDriver $driver, AuditRun $run, RunSelection $selection, ProjectInspector $project, PreflightSummary $summary, array $config): array
    {
        $mcp = (new McpConfigWriter)->write($project, $run->logsDir().'/mcp.json');
        $context = (new PromptContextBuilder)->build($summary, $run, $project, $config, $this->messages->locale, $mcp !== null);
        $renderer = $factory->promptRenderer();
        $extra = str_starts_with($run->dir(), rtrim($project->basePath, '/').'/') ? [] : [$run->outputRoot];

        $jobs = [];

        foreach ($selection->audits as $type) {
            $audit = $type->audit();
            $prompt = $renderer->render($audit, $context);
            file_put_contents($run->logsDir().'/'.$type->value.'.prompt.md', $prompt->text);

            $jobs[] = new AgentJob(
                $audit,
                new AgentRequest($type->value, $prompt->text, $project->basePath, $mcp, $extra),
                $prompt->hash,
                $audit->requiredFindings($summary),
            );
        }

        $this->section('agents');

        $runner = new AgentRunner(
            $driver,
            $factory->validator($project),
            $run->logsDir(),
            (int) ($config['agent']['concurrency'] ?? 4),
            (int) ($config['agent']['timeout'] ?? 1800),
        );

        return $runner->run(
            $jobs,
            fn (AgentJob $job) => $this->line(sprintf('  <fg=gray>…</> %s <fg=gray>%s</>', $job->audit->type()->value, $this->messages->get('agents.running'))),
            fn (AuditOutcome $outcome) => $this->printOutcome($outcome),
        );
    }

    /** @param array<string, AuditOutcome> $outcomes */
    private function printSummary(RunManifest $manifest, array $outcomes, ProjectInspector $project): int
    {
        $findings = [];
        $failed = [];

        foreach ($outcomes as $audit => $outcome) {
            array_push($findings, ...$outcome->findings);

            if (! $outcome->completed()) {
                $failed[] = $audit;
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

    /** @param array<string, mixed> $config */
    private function header(AgentDriver $driver, RunSelection $selection, array $config): void
    {
        $model = $config['agent']['model'] ?? null;

        $this->newLine();
        $this->line(' <options=bold>'.$this->messages->get('header', [
            'version' => $this->packageVersion(),
            'agent' => $driver->name().($model ? ' ('.$model.')' : ''),
        ]).'</>');

        if ($selection->without !== [] || count($selection->audits) < count(AuditType::cases())) {
            $this->line(' <fg=gray>'.implode(', ', $selection->auditValues()).($selection->without === [] ? '' : ' · '.$this->messages->get('without', ['items' => implode(', ', $selection->withoutValues())])).'</>');
        }

        $this->section('requirements');
    }

    /** @param list<\Astro2006\LaravelAudit\Requirements\Check> $checks */
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

    private function printStep(StepResult $result): void
    {
        $line = $this->formatter->format($result);
        $this->components->twoColumnDetail(sprintf('<fg=%s>%s</> %s', $line['color'], $line['symbol'], $line['label']), $line['detail']);

        foreach ($this->formatter->warnings($result) as $warning) {
            $this->line('    <fg=yellow>⚠</> '.$warning);
        }
    }

    private function printOutcome(AuditOutcome $outcome): void
    {
        $name = $outcome->audit->value;

        if (! $outcome->completed()) {
            $this->components->twoColumnDetail('<fg=red>✘</> '.$name, $this->messages->get('agents.failed', ['reason' => mb_strimwidth((string) $outcome->reason, 0, 90, '…')]));

            return;
        }

        $t = $outcome->telemetry;
        $detail = $this->messages->get('agents.finished', [
            'findings' => count($outcome->findings),
            'turns' => $t->turns,
            'input' => $this->tokens($t->inputTokens + $t->cacheReadTokens),
            'output' => $this->tokens($t->outputTokens),
            'cost' => $t->costUsd === null ? '–' : '$'.number_format($t->costUsd, 2),
            'duration' => sprintf('%dm%02ds', intdiv(intdiv($t->durationMs, 1000), 60), intdiv($t->durationMs, 1000) % 60),
        ]).($outcome->repaired ? ' · '.$this->messages->get('agents.repaired') : '');

        $this->components->twoColumnDetail('<fg=green>✔</> '.$name, $detail);
    }

    private function section(string $key): void
    {
        $this->newLine();
        $this->line(' <options=bold>'.$this->messages->get('sections.'.$key).'</>');
    }

    private function browserLabel(PreflightSummary $summary): string
    {
        return match ($summary->status(PreflightStep::Browser)) {
            StepStatus::Ok => 'playwright',
            StepStatus::Skipped => 'skipped ('.$summary->get('steps.browser.reason').')',
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
