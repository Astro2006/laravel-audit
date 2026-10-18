<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;
use Astro2006\LaravelAudit\Agents\TaskLog;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Facades\Process;

final class OrchestratorSession
{
    public const string KEY = 'orchestrator';

    public function __construct(private readonly AuditConfig $config) {}

    /**
     * Runs one headless session with the orchestrator prompt and keeps its
     * closing message as `orchestrator.md` in the run folder.
     *
     * @return AgentResult the parsed session result; its answer is the closing message
     */
    public function run(AgentDriver $driver, AuditRun $run, ProjectInspector $project, string $prompt): AgentResult
    {
        $request = new AgentRequest(self::KEY, $prompt, $project->basePath, $driver->writeConfig($run->logsDir()), maxTurns: $this->config->int('agent.orchestrator.max_turns'));
        file_put_contents($run->logsDir().'/'.self::KEY.'.prompt.md', $prompt);

        $result = Process::path($project->basePath)
            ->timeout(max(60, $this->config->int('agent.orchestrator.timeout')))
            ->env([...ChildEnvironment::forApp($project)->overrides(), ...$driver->environment($request), 'AUDIT_TASK' => self::KEY])
            ->input($prompt)
            ->run($driver->orchestratorCommand($request, $this->config->nullableFloat('agent.orchestrator.max_budget_usd')));

        (new TaskLog($run->logsDir()))->write(self::KEY, $result->output(), $result->errorOutput());
        $parsed = $driver->parse($result->output(), (int) $result->exitCode());

        if (trim($parsed->answer) !== '') {
            file_put_contents($run->dir().'/'.self::KEY.'.md', trim($parsed->answer)."\n");
        }

        return $parsed;
    }
}
