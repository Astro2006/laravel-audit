<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console\Commands;

use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Console\Output\AuditConsole;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Orchestration\OrchestratorPrompt;
use Astro2006\LaravelAudit\Orchestration\OrchestratorSession;
use Astro2006\LaravelAudit\Run\AuditOptions;
use Astro2006\LaravelAudit\Run\Data\RunFrontmatter;
use Astro2006\LaravelAudit\Run\Phases\CheckModel;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Astro2006\LaravelAudit\Tool\Finish;
use Astro2006\LaravelAudit\Tool\Open;
use Astro2006\LaravelAudit\Tool\OpenedRun;
use Astro2006\LaravelAudit\Tool\Pause;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class OrchestrateCommand extends Command
{
    protected $signature = 'audit:orchestrate
        {--audits=* : The audits to run (tests, frontend, backend, dependencies); default: the configured ones}
        {--without=* : Audits and steps to leave out}
        {--agent= : Agent CLI for the orchestrator and the tasks: claude_code, opencode or auto}
        {--model= : Model for the tasks; agent.orchestrator.model sets the orchestrator\'s}
        {--concurrency= : Tasks at once; 1 = one after the other}
        {--force : Continue past failing tests without asking}';

    protected $description = 'Let a headless agent session drive the audit through audit:tool: it picks steps and tasks, reruns failures and writes the reports';

    public function handle(AuditConfig $config, Open $open, OrchestratorSession $session, Finish $finish, CheckModel $checkModel): int
    {
        $list = fn (string $name): array => array_values(array_filter(array_map('trim', explode(',', implode(',', array_map('strval', (array) $this->option($name)))))));
        $models = $this->checkModels($config, $checkModel);

        if ($models === null) {
            return AuditExit::HarnessError->value;
        }

        [$taskModel, $orchestratorModel] = $models;
        $options = new AuditOptions(audits: $list('audits'), without: $list('without'), force: (bool) $this->option('force'), agent: $this->stringOption('agent'), model: $taskModel, concurrency: $this->stringOption('concurrency'));
        $opened = $open->handle($options, Messages::fromConfig());

        if (Arr::has($opened, 'error')) {
            $this->components->error(Read::string($opened, 'error').' '.implode(' ', Read::strings($opened, 'problems')));

            return AuditExit::HarnessError->value;
        }

        $run = OpenedRun::open($config, Read::string($opened, 'run'));
        $driver = (new DriverRegistry($config, $run->plan->project))->resolve($options->agent, $orchestratorModel);
        $this->components->info(sprintf('Run %s: %s drives the audit through audit:tool …', $run->run->id, $driver->displayName()));

        $result = $session->run($driver, $run->run, $run->plan->project, OrchestratorPrompt::render($run->run->id));

        if (! $result->succeeded()) {
            $this->components->warn('The orchestrator session ended with: '.($result->error ?? $result->exit->value));
        }

        if (RunFrontmatter::read($run->run->manifestPath())?->status === RunStatus::Running->value) {
            $finished = $finish->handle(OpenedRun::open($config, $run->run->id), AuditConsole::quiet());

            if (Arr::has($finished, 'error')) {
                $this->components->error('The agent did not finish the run and it cannot be finished: '.Read::string($finished, 'error'));

                return AuditExit::HarnessError->value;
            }
        }

        $this->line(' → '.$run->plan->project->relative($run->run->manifestPath()));

        if (is_file($run->run->dir().'/'.OrchestratorSession::KEY.'.md')) {
            $this->line(' → '.$run->plan->project->relative($run->run->dir().'/'.OrchestratorSession::KEY.'.md'));
        }

        return match (true) {
            Pause::read($run->run) !== null => AuditExit::Paused->value,
            RunFrontmatter::read($run->run->manifestPath())?->status === RunStatus::Completed->value => AuditExit::Ok->value,
            RunFrontmatter::read($run->run->manifestPath())?->status === RunStatus::Aborted->value => AuditExit::GateAborted->value,
            default => AuditExit::HarnessError->value,
        };
    }

    /** @return array{0: string|null, 1: string|null}|null the models of the tasks and of the orchestrator; null when one does not answer */
    private function checkModels(AuditConfig $config, CheckModel $checkModel): ?array
    {
        $project = ProjectInspector::fromConfig($config);
        $registry = new DriverRegistry($config, $project);
        $console = new AuditConsole($this->output, Messages::fromConfig(), $this->input->isInteractive());

        try {
            $tasks = $checkModel->handle($registry->resolve($this->stringOption('agent'), $this->stringOption('model')), $project, $console);

            if ($tasks === null) {
                return null;
            }

            $orchestratorModel = $config->nullableString('agent.orchestrator.model');
            $orchestrator = $orchestratorModel === null || $orchestratorModel === $tasks->model() ? $tasks : $checkModel->handle($registry->resolve($this->stringOption('agent'), $orchestratorModel), $project, $console);
        } catch (InvalidSelection) {
            return [$this->stringOption('model'), $config->nullableString('agent.orchestrator.model') ?? $this->stringOption('model')];
        }

        return $orchestrator === null ? null : [$tasks->model(), $orchestrator->model()];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
