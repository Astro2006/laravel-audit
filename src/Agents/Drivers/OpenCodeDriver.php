<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;
use Astro2006\LaravelAudit\Agents\LimitSignals;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\McpServer;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class OpenCodeDriver implements AgentDriver
{
    use RunsCli;

    public const string NAME = 'opencode';

    public const string AGENT = 'laravel-audit';

    public const string VERIFY_AGENT = 'laravel-audit-verify';

    public const string ANSWER_AGENT = 'laravel-audit-answer';

    public const string ORCHESTRATOR_AGENT = 'laravel-audit-orchestrator';

    /**
     * @param  string  $basePath  project root; deny rules match absolute paths too
     * @param  list<string>  $boostTools  allowed Boost MCP tools
     * @param  list<string>  $deniedFiles  project-relative files the agent must not read
     * @param  list<int>  $taskSteps  turn caps tasks set for themselves; each gets its own agent
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $binary = 'opencode',
        private readonly ?string $model = null,
        private readonly ?int $maxTurns = null,
        private readonly ?int $verifyMaxTurns = null,
        private readonly ?McpServer $boost = null,
        private readonly array $boostTools = [],
        private readonly array $deniedFiles = [],
        private readonly array $taskSteps = [],
        private readonly ?int $orchestratorMaxTurns = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function displayName(): string
    {
        return 'OpenCode';
    }

    public function toolsNote(): string
    {
        return 'You can read files and find files by name (read, glob, list). There is no content search: open the files you need. There is no shell, no web access and no write access. `.env` files are blocked on purpose.';
    }

    public function writeConfig(string $directory, array $extraDirectories = []): string
    {
        $path = $directory.'/opencode.json';
        Json::writeFile($path, (new OpenCodeConfig($this->basePath, $this->boost, $this->boostTools, $this->deniedFiles, $this->maxTurns, $this->verifyMaxTurns, $this->taskSteps, $this->orchestratorMaxTurns))->toArray($extraDirectories));

        return $path;
    }

    public function command(AgentRequest $request): array
    {
        return [$this->binary, 'run', '--pure', '--format', 'json', '--agent', match (true) {
            $request->answerOnly => self::ANSWER_AGENT,
            $request->verify => self::VERIFY_AGENT,
            $request->maxTurns !== null => self::AGENT.'-'.$request->maxTurns,
            default => self::AGENT,
        }, ...$this->modelArguments()];
    }

    public function resumeCommand(AgentResult $previous, AgentRequest $request): array
    {
        return [...$this->command($request), '--session', (string) $previous->sessionId];
    }

    public function orchestratorCommand(AgentRequest $request, ?float $maxBudgetUsd = null): array
    {
        return [$this->binary, 'run', '--pure', '--format', 'json', '--agent', self::ORCHESTRATOR_AGENT, ...$this->modelArguments()];
    }

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
                $texts[$message] = Arr::get($texts, $message, '').Read::string($part, 'text');
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

                if ($lastReason !== 'tool-calls') {
                    $answerMessage = $message;
                }
            }
        }

        $answer = trim($answerMessage !== null && Arr::has($texts, $answerMessage) ? Arr::get($texts, $answerMessage) : (string) end($texts));

        $telemetry = new Telemetry(
            inputTokens: $input,
            outputTokens: $output,
            cacheReadTokens: $cacheRead,
            costUsd: $cost,
            turns: $turns,
            durationMs: $first === null || $last === null ? 0 : max(0, $last - $first),
        );

        if ($error !== null || ($answer === '' && $exitCode !== 0)) {
            return new AgentResult('', $sessionId, $this->model, $telemetry, LimitSignals::matches($error) ? AgentExit::Limit : AgentExit::Error, error: $error ?? sprintf('OpenCode exited with code %d.', $exitCode));
        }

        if ($turns === 0 && $answer === '') {
            return AgentResult::failed(AgentExit::Error, sprintf('OpenCode returned no events (exit code %d).', $exitCode));
        }

        if ($answer === '' && $output === 0) {
            return new AgentResult('', $sessionId, $this->model, $telemetry, AgentExit::Error, error: sprintf('The model sent %d empty replies. Check the provider: it may be overloaded or not serve this model.', $turns));
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
