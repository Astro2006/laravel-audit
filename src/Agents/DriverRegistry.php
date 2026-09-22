<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Agents\Drivers\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\Drivers\CodexDriver;
use Astro2006\LaravelAudit\Agents\Drivers\OpenCodeDriver;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

/**
 * Builds the agent driver from config/audit.php. Driver names follow
 * Laravel Boost's agent names, so `auto` can pick the agent the project
 * already set up with `boost:install` (boost.json).
 */
final class DriverRegistry
{
    public const string AUTO = 'auto';

    /** Fallback order when boost.json names no agent with a driver. */
    public const array DRIVERS = [ClaudeCodeDriver::NAME, OpenCodeDriver::NAME, CodexDriver::NAME];

    private const array ALIASES = ['claude' => ClaudeCodeDriver::NAME];

    /** @param array<string, mixed> $config the whole audit config */
    public function __construct(
        private readonly array $config,
        private readonly ProjectInspector $project,
    ) {}

    /**
     * @param  string|null  $name  driver name, alias or `auto`; null = agent.driver from the config
     * @param  string|null  $model  overrides the configured model
     *
     * @throws InvalidSelection for an unknown driver name
     */
    public function resolve(?string $name = null, ?string $model = null): AgentDriver
    {
        $name = $this->normalize($name ?? (string) $this->config('agent.driver', self::AUTO));

        if ($name === self::AUTO) {
            return $this->auto($model);
        }

        if (! in_array($name, self::DRIVERS, true)) {
            throw new InvalidSelection(sprintf('Unknown agent driver "%s". Available: %s, auto.', $name, implode(', ', self::DRIVERS)));
        }

        return $this->make($name, $model);
    }

    /**
     * Agents from boost.json that have a driver here, in the order Boost lists them.
     *
     * @return list<string>
     */
    public function boostAgents(): array
    {
        $agents = Read::strings(Json::readFile($this->project->path('boost.json')), 'agents');

        return array_values(array_filter(
            array_map(fn (string $agent): string => $this->normalize($agent), $agents),
            fn (string $agent): bool => in_array($agent, self::DRIVERS, true),
        ));
    }

    private function auto(?string $model): AgentDriver
    {
        $candidates = array_values(array_unique([...$this->boostAgents(), ...self::DRIVERS]));

        foreach ($candidates as $name) {
            $driver = $this->make($name, $model);

            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        // Nothing installed: return the first candidate so the requirement
        // check can name the CLI that is missing.
        return $this->make($candidates[0], $model);
    }

    private function make(string $name, ?string $model): AgentDriver
    {
        $model = $this->filled($model) ?? $this->filled($this->config('agent.model')) ?? $this->filled($this->config("agent.drivers.{$name}.model"));
        $boost = $this->project->boostMcpServer();
        $boostTools = array_values(array_map('strval', (array) $this->config('agent.boost_tools', [])));
        $deniedFiles = DeniedFiles::resolve(
            $this->project->basePath,
            array_values(array_map('strval', (array) $this->config('agent.deny_read', []))),
            array_values(array_map('strval', (array) $this->config('agent.allow_read', []))),
        );

        return match ($name) {
            OpenCodeDriver::NAME => new OpenCodeDriver(
                basePath: $this->project->basePath,
                binary: $this->binary($name, 'opencode'),
                model: $model,
                boost: $boost,
                boostTools: $boostTools,
                deniedFiles: $deniedFiles,
            ),
            CodexDriver::NAME => new CodexDriver(
                binary: $this->binary($name, 'codex'),
                model: $model,
                boost: $boost,
                boostTools: $boostTools,
                allowUnrestrictedRead: (bool) $this->config("agent.drivers.{$name}.allow_unrestricted_read", false),
                overrides: array_values(array_map('strval', (array) $this->config("agent.drivers.{$name}.config", []))),
            ),
            default => new ClaudeCodeDriver(
                // agent.binary, agent.effort and agent.max_budget_usd are the
                // pre-driver keys; published configs may still use them.
                binary: $this->binary($name, (string) $this->config('agent.binary', 'claude')),
                model: $model,
                effort: $this->filled($this->config("agent.drivers.{$name}.effort")) ?? $this->filled($this->config('agent.effort')),
                maxBudgetUsd: $this->budget(),
                boost: $boost,
                boostTools: $boostTools,
                deniedFiles: $deniedFiles,
            ),
        };
    }

    private function binary(string $name, string $default): string
    {
        return $this->filled($this->config("agent.drivers.{$name}.binary")) ?? $default;
    }

    private function budget(): ?float
    {
        $budget = $this->config('agent.drivers.'.ClaudeCodeDriver::NAME.'.max_budget_usd') ?? $this->config('agent.max_budget_usd');

        return is_numeric($budget) ? (float) $budget : null;
    }

    private function normalize(string $name): string
    {
        $name = strtolower(trim($name));

        return self::ALIASES[$name] ?? ($name === '' ? self::AUTO : $name);
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }
}
