<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console\Commands;

use Astro2006\LaravelAudit\Console\Output\AuditConsole;
use Astro2006\LaravelAudit\Enums\AuditExit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Run\AuditOptions;
use Astro2006\LaravelAudit\Run\AuditOrchestrator;
use Astro2006\LaravelAudit\Support\Messages;
use Illuminate\Console\Command;

class AuditCommand extends Command
{
    public const int OK = AuditExit::Ok->value;

    public const int GATE_ABORTED = AuditExit::GateAborted->value;

    public const int HARNESS_ERROR = AuditExit::HarnessError->value;

    protected $signature = 'audit
        {--tests : Run the tests audit}
        {--frontend : Run the frontend audit}
        {--backend : Run the backend audit}
        {--dependencies : Run the dependencies audit}
        {--without=* : Leave out audits or steps for this run: tests, frontend, backend, dependencies, browser, phpstan, pint, coverage}
        {--force : Start the agents even if tests or browser tests fail, without asking}
        {--preflight-only : Run the tools only, without agents}
        {--reuse-preflight= : Reuse the preflight of the latest run, or of the given run ID}
        {--resume= : Continue the latest run, or the given run ID: tasks with a completed result keep it, the others run}
        {--agent= : Agent CLI for this run: claude_code, opencode or auto}
        {--model= : Model for this run, in the format the agent CLI expects}
        {--concurrency= : Tasks at once for this run; 1 = one after the other; default agent.max_concurrency}
        {--reselect-skills : Ask the agent again which skills each task gets, instead of reusing the last choice}';

    protected $description = 'Audit this app: a deterministic preflight, then headless agents (Claude Code or OpenCode) for tests, frontend, backend and dependencies';

    public function handle(AuditOrchestrator $orchestrator): int
    {
        return $orchestrator->run(
            new AuditOptions(
                audits: array_values(array_filter(AuditType::values(), fn (string $audit): bool => (bool) $this->option($audit))),
                without: array_values(array_map('strval', (array) $this->option('without'))),
                force: (bool) $this->option('force'),
                preflightOnly: (bool) $this->option('preflight-only'),
                reusePreflight: $this->input->hasParameterOption('--reuse-preflight'),
                reuseRunId: $this->stringOption('reuse-preflight'),
                agent: $this->stringOption('agent'),
                model: $this->stringOption('model'),
                concurrency: $this->stringOption('concurrency'),
                reselectSkills: (bool) $this->option('reselect-skills'),
                resume: $this->input->hasParameterOption('--resume'),
                resumeRunId: $this->stringOption('resume'),
            ),
            new AuditConsole($this->output, Messages::fromConfig(), $this->input->isInteractive()),
        )->value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
