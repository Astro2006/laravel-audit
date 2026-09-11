<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\ReadProtection;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\McpServer;
use Astro2006\LaravelAudit\Support\Read;
use Symfony\Component\Process\ExecutableFinder;

/**
 * OpenCode, started headless with `opencode run --format json`.
 *
 * The run gets its own config (OPENCODE_CONFIG) with a `laravel-audit` agent:
 * every tool off except read, glob and list, every other permission denied,
 * read denied for the secret files, only the Boost MCP tools that are
 * allowed. OpenCode's grep tool is left out on purpose: with an `include`
 * pattern it returns the content of files the read rules deny.
 * Models come from the user's OpenCode config (`provider/model`), which is
 * how self-hosted models (Ollama and others) get in.
 */
final class OpenCodeDriver implements AgentDriver
{
    public const string NAME = 'opencode';

    public const string AGENT = 'laravel-audit';

    private const array TOOLS = ['read', 'glob', 'list'];

    private const array DENIED_PERMISSIONS = ['edit', 'bash', 'webfetch', 'websearch', 'task', 'skill', 'question', 'lsp', 'doom_loop'];

    /**
     * @param  string  $basePath  project root; deny rules match absolute paths too
     * @param  list<string>  $boostTools  allowed Boost MCP tools
     * @param  list<string>  $deniedFiles  project-relative files the agent must not read
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $binary = 'opencode',
        private readonly ?string $model = null,
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
        return 'OpenCode';
    }

    public function binary(): string
    {
        return $this->binary;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function isAvailable(): bool
    {
        return is_file($this->binary) || (new ExecutableFinder)->find($this->binary) !== null;
    }

    public function readProtection(): ReadProtection
    {
        return ReadProtection::Enforced;
    }

    public function toolsNote(): string
    {
        return 'You can read files and find files by name (read, glob, list). There is no content search: open the files you need. There is no shell, no web access and no write access. `.env` files are blocked on purpose.';
    }

    public function writeConfig(string $directory, array $extraDirectories = []): string
    {
        $tools = ['*' => false];

        foreach (self::TOOLS as $tool) {
            $tools[$tool] = true;
        }

        if ($this->boost !== null) {
            foreach (McpServer::allowedTools($this->boostTools) as $tool) {
                $tools[McpServer::NAME.'_'.$tool] = true;
            }
        }

        $permission = array_fill_keys(self::DENIED_PERMISSIONS, 'deny');
        $permission['read'] = ['*' => 'allow'];

        foreach ($this->deniedFiles as $file) {
            $file = str_starts_with($file, './') ? substr($file, 2) : $file;
            $permission['read'][$file] = 'deny';
            $permission['read'][rtrim($this->basePath, '/').'/'.$file] = 'deny';
        }

        $permission['external_directory'] = ['*' => 'deny'];

        foreach ($extraDirectories as $extra) {
            $permission['external_directory'][rtrim($extra, '/').'/*'] = 'allow';
        }

        $config = [
            '$schema' => 'https://opencode.ai/config.json',
            'agent' => [
                self::AGENT => [
                    'description' => 'Read-only Laravel audit agent',
                    'mode' => 'primary',
                    'tools' => $tools,
                    'permission' => $permission,
                ],
            ],
        ];

        if ($this->boost !== null) {
            $config['mcp'] = [
                McpServer::NAME => array_filter([
                    'type' => 'local',
                    'command' => [$this->boost->command(), ...$this->boost->args()],
                    'environment' => $this->boost->env() === [] ? null : $this->boost->env(),
                    'enabled' => true,
                ], fn (mixed $value): bool => $value !== null),
            ];
        }

        $path = $directory.'/opencode.json';
        Json::writeFile($path, $config);

        return $path;
    }

    public function command(AgentRequest $request): array
    {
        $command = [$this->binary, 'run', '--pure', '--format', 'json', '--agent', self::AGENT];

        if ($this->model !== null && $this->model !== '') {
            array_push($command, '--model', $this->model);
        }

        return $command;
    }

    public function resumeCommand(AgentResult $previous, AgentRequest $request): array
    {
        return [...$this->command($request), '--session', (string) $previous->sessionId];
    }

    /**
     * Reads the JSON event stream: step_start, text, tool_use, step_finish
     * (tokens, cost, reason) and error events, one per line.
     */
    public function parse(string $stdout, int $exitCode): AgentResult
    {
        $sessionId = null;
        $texts = [];
        $answerMessage = null;
        $lastReason = null;
        $error = null;
        $input = 0;
        $output = 0;
        $cacheRead = 0;
        $cost = null;
        $turns = 0;
        $first = null;
        $last = null;

        foreach (explode("\n", $stdout) as $line) {
            $event = Json::decode(trim($line));

            if ($event === []) {
                continue;
            }

            $sessionId ??= Read::nullableString($event, 'sessionID');
            $timestamp = Read::nullableInt($event, 'timestamp');
            $first ??= $timestamp;
            $last = $timestamp ?? $last;
            $part = Read::array($event, 'part');
            $message = Read::string($part, 'messageID');
            $type = Read::string($event, 'type');

            if ($type === 'text') {
                $texts[$message] = ($texts[$message] ?? '').Read::string($part, 'text');
            } elseif ($type === 'error') {
                $error = Read::nullableString($event, 'error.data.message') ?? Read::string($event, 'error.name', 'unknown error');
            } elseif ($type === 'step_finish') {
                $turns++;
                $input += Read::int($part, 'tokens.input') + Read::int($part, 'tokens.cache.write');
                $output += Read::int($part, 'tokens.output') + Read::int($part, 'tokens.reasoning');
                $cacheRead += Read::int($part, 'tokens.cache.read');
                $stepCost = Read::nullableFloat($part, 'cost');
                $cost = $stepCost === null ? $cost : ($cost ?? 0.0) + $stepCost;
                $lastReason = Read::string($part, 'reason');

                // The answer is the text of the last step that did not end in tool calls.
                if ($lastReason !== 'tool-calls') {
                    $answerMessage = $message;
                }
            }
        }

        $answer = trim($answerMessage !== null && isset($texts[$answerMessage]) ? $texts[$answerMessage] : (string) end($texts));

        $telemetry = new Telemetry(
            inputTokens: $input,
            outputTokens: $output,
            cacheReadTokens: $cacheRead,
            costUsd: $cost,
            turns: $turns,
            durationMs: $first === null || $last === null ? 0 : max(0, $last - $first),
        );

        if ($error !== null || ($answer === '' && $exitCode !== 0)) {
            return new AgentResult('', $sessionId, $this->model, $telemetry, AgentExit::Error, error: $error ?? sprintf('OpenCode exited with code %d.', $exitCode));
        }

        if ($turns === 0 && $answer === '') {
            return AgentResult::failed(AgentExit::Error, sprintf('OpenCode returned no events (exit code %d).', $exitCode));
        }

        return new AgentResult(
            answer: $answer,
            sessionId: $sessionId,
            model: $this->model,
            telemetry: $telemetry,
            exit: AgentExit::Success,
            truncated: $lastReason === 'length',
        );
    }

    public function environment(AgentRequest $request): array
    {
        return $request->configFile === null ? [] : ['OPENCODE_CONFIG' => $request->configFile];
    }
}
