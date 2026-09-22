<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\ReadProtection;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\McpServer;
use Astro2006\LaravelAudit\Support\Read;

/**
 * OpenAI Codex, started headless with `codex exec --json`.
 *
 * Codex works through a shell. The read-only sandbox stops writes and
 * network access, but not reading files: Codex cannot keep an agent away
 * from .env. A run with this driver therefore only starts when the config
 * accepts that (agent.drivers.codex.allow_unrestricted_read).
 * Models and providers (including self-hosted ones) come from the user's
 * Codex config; extra `-c key=value` overrides can be set per run.
 */
final class CodexDriver implements AgentDriver
{
    use RunsCli;

    public const string NAME = 'codex';

    /**
     * @param  list<string>  $boostTools  allowed Boost MCP tools
     * @param  list<string>  $overrides  extra `-c key=value` overrides, e.g. model_provider="ollama"
     */
    public function __construct(
        private readonly string $binary = 'codex',
        private readonly ?string $model = null,
        private readonly ?McpServer $boost = null,
        private readonly array $boostTools = [],
        private readonly bool $allowUnrestrictedRead = false,
        private readonly array $overrides = [],
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function displayName(): string
    {
        return 'Codex';
    }

    public function readProtection(): ReadProtection
    {
        return $this->allowUnrestrictedRead ? ReadProtection::Waived : ReadProtection::Missing;
    }

    public function toolsNote(): string
    {
        return 'You work in a read-only sandbox: read and search files with read-only shell commands (cat, sed -n, rg, ls). Never modify files, never run the app, artisan, tests or network commands, and never open `.env` files or other secrets.';
    }

    public function writeConfig(string $directory, array $extraDirectories = []): ?string
    {
        return null;
    }

    public function command(AgentRequest $request): array
    {
        return [...$this->base(), '--sandbox', 'read-only', '-'];
    }

    public function resumeCommand(AgentResult $previous, AgentRequest $request): array
    {
        return [...$this->base(), 'resume', (string) $previous->sessionId, '-'];
    }

    /**
     * Reads the JSON Lines stream: thread.started (thread_id), item.completed
     * (agent_message text), turn.completed (usage), turn.failed and error.
     */
    public function parse(string $stdout, int $exitCode): AgentResult
    {
        $threadId = null;
        $answer = '';
        $error = null;
        $input = 0;
        $output = 0;
        $cacheRead = 0;
        $turns = 0;

        foreach (explode("\n", $stdout) as $line) {
            $event = Json::decode(trim($line));
            $type = Read::string($event, 'type');

            if ($type === 'thread.started') {
                $threadId = Read::nullableString($event, 'thread_id');
            } elseif ($type === 'item.completed') {
                $turns++;

                if (Read::string($event, 'item.type') === 'agent_message') {
                    $answer = Read::string($event, 'item.text');
                }
            } elseif ($type === 'turn.completed') {
                $cached = Read::int($event, 'usage.cached_input_tokens');
                $input += Read::int($event, 'usage.input_tokens') - $cached;
                $cacheRead += $cached;
                $output += Read::int($event, 'usage.output_tokens') + Read::int($event, 'usage.reasoning_output_tokens');
            } elseif ($type === 'turn.failed' || $type === 'error') {
                $error = Read::nullableString($event, 'error.message') ?? Read::nullableString($event, 'message') ?? $type;
            }
        }

        $telemetry = new Telemetry(inputTokens: max(0, $input), outputTokens: $output, cacheReadTokens: $cacheRead, turns: $turns);

        if ($error !== null || ($answer === '' && $exitCode !== 0)) {
            return new AgentResult('', $threadId, $this->model, $telemetry, AgentExit::Error, error: $error ?? sprintf('Codex exited with code %d.', $exitCode));
        }

        if ($threadId === null && $answer === '') {
            return AgentResult::failed(AgentExit::Error, sprintf('Codex returned no events (exit code %d).', $exitCode));
        }

        return new AgentResult(trim($answer), $threadId, $this->model, $telemetry, AgentExit::Success);
    }

    public function environment(AgentRequest $request): array
    {
        return [];
    }

    /** @return list<string> */
    private function base(): array
    {
        $command = [$this->binary, 'exec', '--json', '--skip-git-repo-check', ...$this->modelArguments()];

        foreach ([...$this->mcpOverrides(), ...$this->overrides] as $override) {
            array_push($command, '-c', $override);
        }

        return $command;
    }

    /**
     * Boost as the only MCP server, with the tool allowlist. Values are TOML;
     * JSON strings and string arrays are valid TOML.
     *
     * @return list<string>
     */
    private function mcpOverrides(): array
    {
        if ($this->boost === null) {
            return ['mcp_servers={}'];
        }

        $key = 'mcp_servers.'.McpServer::NAME;
        $toml = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'mcp_servers={}',
            $key.'.command='.$toml($this->boost->command()),
            $key.'.args='.$toml($this->boost->args()),
            $key.'.enabled_tools='.$toml(McpServer::allowedTools($this->boostTools)),
        ];
    }
}
