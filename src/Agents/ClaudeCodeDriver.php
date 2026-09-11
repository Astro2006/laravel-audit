<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Claude Code, started headless with `claude -p`.
 *
 * Read-only is enforced by the CLI, not by the prompt: restricted mode (no
 * code-running tools, project and user settings ignored), only Read, Grep
 * and Glob, only the Boost MCP server with an allowlist, deny rules for
 * secret files, and every permission prompt answered with "no".
 */
final class ClaudeCodeDriver implements AgentDriver
{
    public const string MCP_SERVER = 'laravel-boost';

    private const array TOOLS = ['Read', 'Grep', 'Glob'];

    private const array ALWAYS_DENIED = ['Edit', 'Write', 'NotebookEdit', 'Bash', 'WebFetch', 'WebSearch'];

    /** Boost tools that execute code, query data or read logs. */
    private const array DENIED_BOOST_TOOLS = ['tinker', 'database-query', 'read-log-entries', 'last-error', 'browser-logs', 'list-available-env-vars'];

    /** Variables that tie a child process to a parent Claude Code session. */
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
        private readonly array $boostTools = [],
        private readonly array $deniedFiles = [],
    ) {}

    public function name(): string
    {
        return 'claude';
    }

    public function isAvailable(): bool
    {
        return is_file($this->binary) || (new ExecutableFinder)->find($this->binary) !== null;
    }

    public function command(AgentRequest $request): array
    {
        $command = [
            $this->binary,
            '-p',
            '--output-format', 'json',
            '--restricted',
            '--permission-prompts', 'none',
            '--strict-mcp-config',
        ];

        if ($request->mcpConfig !== null) {
            $command[] = '--mcp-config';
            $command[] = $request->mcpConfig;
        }

        $command[] = '--tools';
        $command[] = implode(',', self::TOOLS);

        $allowed = $request->mcpConfig === null ? [] : array_map(
            fn (string $tool): string => 'mcp__'.self::MCP_SERVER.'__'.$tool,
            array_values(array_diff($this->boostTools, self::DENIED_BOOST_TOOLS)),
        );

        if ($allowed !== []) {
            $command[] = '--allowedTools='.implode(',', $allowed);
        }

        $command[] = '--disallowedTools='.implode(',', $this->deniedTools());

        if ($this->model !== null && $this->model !== '') {
            array_push($command, '--model', $this->model);
        }

        if ($this->effort !== null && $this->effort !== '') {
            array_push($command, '--effort', $this->effort);
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
            default => AgentExit::Error,
        };

        [$telemetry, $model] = $this->usage($data);
        $answer = Read::string($data, 'result');

        return new AgentResult(
            answer: $answer,
            sessionId: Read::nullableString($data, 'session_id'),
            model: $model,
            telemetry: $telemetry,
            exit: $exit,
            truncated: Read::string($data, 'stop_reason') === 'max_tokens',
            error: $exit === AgentExit::Success ? null : trim($subtype.' '.mb_substr($answer, 0, 300)),
        );
    }

    public function environment(): array
    {
        return array_fill_keys(self::SESSION_VARIABLES, false);
    }

    /** @return list<string> */
    private function deniedTools(): array
    {
        $denied = self::ALWAYS_DENIED;

        foreach (self::DENIED_BOOST_TOOLS as $tool) {
            $denied[] = 'mcp__'.self::MCP_SERVER.'__'.$tool;
        }

        foreach ($this->deniedFiles as $file) {
            $denied[] = 'Read(./'.(str_starts_with($file, './') ? substr($file, 2) : $file).')';
        }

        return $denied;
    }

    /**
     * Sums token usage over all models (the CLI may use a small model for
     * housekeeping) and names the model that did most of the work.
     *
     * @param  array<mixed>  $data
     * @return array{Telemetry, string|null}
     */
    private function usage(array $data): array
    {
        $input = 0;
        $output = 0;
        $cacheRead = 0;
        $model = null;
        $topCost = -1.0;

        foreach (Read::array($data, 'modelUsage') as $name => $usage) {
            $usage = is_array($usage) ? $usage : [];
            $input += Read::int($usage, 'inputTokens') + Read::int($usage, 'cacheCreationInputTokens');
            $output += Read::int($usage, 'outputTokens');
            $cacheRead += Read::int($usage, 'cacheReadInputTokens');

            if (Read::float($usage, 'costUSD') > $topCost) {
                $topCost = Read::float($usage, 'costUSD');
                $model = Read::nullableString($usage, 'canonicalModel') ?? (string) preg_replace('/\[.*\]$/', '', (string) $name);
            }
        }

        if ($input === 0) {
            $input = Read::int($data, 'usage.input_tokens') + Read::int($data, 'usage.cache_creation_input_tokens');
            $output = Read::int($data, 'usage.output_tokens');
            $cacheRead = Read::int($data, 'usage.cache_read_input_tokens');
        }

        return [
            new Telemetry(
                inputTokens: $input,
                outputTokens: $output,
                cacheReadTokens: $cacheRead,
                costUsd: Read::nullableFloat($data, 'total_cost_usd'),
                turns: Read::int($data, 'num_turns'),
                durationMs: Read::int($data, 'duration_ms'),
            ),
            $model,
        ];
    }
}
