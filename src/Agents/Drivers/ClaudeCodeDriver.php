<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;
use Astro2006\LaravelAudit\Agents\LimitSignals;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\McpServer;
use Astro2006\LaravelAudit\Support\Read;

final class ClaudeCodeDriver implements AgentDriver
{
    use RunsCli;

    public const string NAME = 'claude_code';

    private const array TOOLS = ['Read', 'Grep', 'Glob'];

    private const array MODEL_ALIASES = ['fable', 'opus', 'sonnet', 'haiku'];

    private const array ALWAYS_DENIED = ['Edit', 'Write', 'NotebookEdit', 'Bash', 'WebFetch', 'WebSearch'];

    private const array ORCHESTRATOR_DENIED = ['Edit', 'Write', 'NotebookEdit', 'WebFetch', 'WebSearch', 'Read', 'Grep', 'Glob', 'Agent', 'Skill'];

    private const array SESSION_VARIABLES = [
        'CLAUDECODE', 'CLAUDE_CODE_ENTRYPOINT', 'CLAUDE_CODE_SSE_PORT', 'CLAUDE_CODE_SESSION_ID',
        'CLAUDE_CODE_CHILD_SESSION', 'CLAUDE_CODE_MESSAGING_SOCKET', 'CLAUDE_CODE_MESSAGING_TOKEN',
        'CLAUDE_CODE_BRIDGE_SESSION_ID', 'CLAUDE_PID', 'CLAUDE_EFFORT',
    ];

    /**
     * @param  list<string>  $boostTools  allowed Boost MCP tools
     * @param  list<string>  $deniedFiles  project-relative files the agent must not read
     */
    public function __construct(
        private readonly string $binary = 'claude',
        private readonly ?string $model = null,
        private readonly ?string $effort = null,
        private readonly ?float $maxBudgetUsd = null,
        private readonly ?int $maxTurns = null,
        private readonly ?int $verifyMaxTurns = null,
        private readonly ?McpServer $boost = null,
        private readonly array $boostTools = [],
        private readonly array $deniedFiles = [],
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function displayName(): string
    {
        return 'Claude Code';
    }

    public function toolsNote(): string
    {
        return 'You can read and search files (Read, Grep, Glob). There is no shell, no web access and no write access. `.env` files are blocked on purpose.';
    }

    public function modelsCommand(): ?array
    {
        return null;
    }

    public function models(string $listed): array
    {
        return self::MODEL_ALIASES;
    }

    public function writeConfig(string $directory, array $extraDirectories = []): ?string
    {
        if ($this->boost === null) {
            return null;
        }

        $path = $directory.'/mcp.json';
        Json::writeFile($path, ['mcpServers' => [McpServer::NAME => $this->boost->config]]);

        return $path;
    }

    public function command(AgentRequest $request): array
    {
        if ($request->answerOnly) {
            return [$this->binary, '-p', '--output-format', 'json', '--restricted', '--permission-prompts', 'none', '--strict-mcp-config', '--tools', '', ...$this->modelArguments(), '--max-turns', '1'];
        }

        $command = [
            $this->binary,
            '-p',
            '--output-format', 'json',
            '--restricted',
            '--permission-prompts', 'none',
            '--strict-mcp-config',
        ];

        if ($request->configFile !== null) {
            $command[] = '--mcp-config';
            $command[] = $request->configFile;
        }

        $command[] = '--tools';
        $command[] = implode(',', self::TOOLS);

        $allowed = $request->configFile === null ? [] : array_map(
            fn (string $tool): string => 'mcp__'.McpServer::NAME.'__'.$tool,
            McpServer::allowedTools($this->boostTools),
        );

        if ($allowed !== []) {
            $command[] = '--allowedTools='.implode(',', $allowed);
        }

        $command[] = '--disallowedTools='.implode(',', $this->deniedTools());

        array_push($command, ...$this->modelArguments());

        if ($this->effort !== null && $this->effort !== '') {
            array_push($command, '--effort', $this->effort);
        }

        $turns = $request->verify ? $this->verifyMaxTurns ?? $this->maxTurns : $request->maxTurns ?? $this->maxTurns;

        if ($turns !== null) {
            array_push($command, '--max-turns', (string) $turns);
        }

        if ($this->maxBudgetUsd !== null && $this->maxBudgetUsd > 0) {
            array_push($command, '--max-budget-usd', (string) $this->maxBudgetUsd);
        }

        foreach ($request->extraDirectories as $directory) {
            array_push($command, '--add-dir', $directory);
        }

        return $command;
    }

    public function resumeCommand(AgentResult $previous, AgentRequest $request): array
    {
        return [...$this->command($request), '--resume', (string) $previous->sessionId];
    }

    public function orchestratorCommand(AgentRequest $request, ?float $maxBudgetUsd = null): array
    {
        $command = [
            $this->binary, '-p', '--output-format', 'json', '--restricted', '--permission-prompts', 'none', '--strict-mcp-config',
            '--tools', 'Bash',
            '--allowedTools', 'Bash(php artisan audit:tool:*)',
            '--disallowedTools='.implode(',', self::ORCHESTRATOR_DENIED),
            ...$this->modelArguments(),
        ];

        if ($request->maxTurns !== null) {
            array_push($command, '--max-turns', (string) $request->maxTurns);
        }

        if ($maxBudgetUsd !== null && $maxBudgetUsd > 0) {
            array_push($command, '--max-budget-usd', (string) $maxBudgetUsd);
        }

        return $command;
    }

    public function parse(string $stdout, int $exitCode): AgentResult
    {
        $data = Json::decode($stdout);

        if (data_get($data, 'result') === null && data_get($data, 'subtype') === null) {
            return AgentResult::failed(AgentExit::Error, sprintf('Claude Code returned no JSON result (exit code %d).', $exitCode));
        }

        $subtype = Read::string($data, 'subtype');
        $exit = match (true) {
            $subtype === 'success' && ! Read::bool($data, 'is_error') => AgentExit::Success,
            str_contains($subtype, 'max_turns') => AgentExit::MaxTurns,
            str_contains($subtype, 'budget') => AgentExit::Budget,
            LimitSignals::matches($subtype.' '.Read::string($data, 'result')) => AgentExit::Limit,
            default => AgentExit::Error,
        };

        $usage = ClaudeUsage::fromResult($data);
        $answer = Read::string($data, 'result');

        return new AgentResult(
            answer: $answer,
            sessionId: Read::nullableString($data, 'session_id'),
            model: $usage->model,
            telemetry: $usage->telemetry,
            exit: $exit,
            truncated: Read::string($data, 'stop_reason') === 'max_tokens',
            error: $exit === AgentExit::Success ? null : trim(($subtype === 'success' ? '' : $subtype).' '.mb_substr($answer, 0, 300)),
        );
    }

    public function environment(AgentRequest $request): array
    {
        return array_fill_keys(self::SESSION_VARIABLES, false);
    }

    /** @return list<string> */
    private function deniedTools(): array
    {
        $denied = self::ALWAYS_DENIED;

        foreach (McpServer::DENIED_TOOLS as $tool) {
            $denied[] = 'mcp__'.McpServer::NAME.'__'.$tool;
        }

        foreach ($this->deniedFiles as $file) {
            $denied[] = 'Read(./'.(str_starts_with($file, './') ? substr($file, 2) : $file).')';
        }

        return $denied;
    }
}
