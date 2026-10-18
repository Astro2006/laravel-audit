<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Agents\Drivers\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\Drivers\OpenCodeDriver;
use Astro2006\LaravelAudit\Audits\AuditDefinition;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\McpServer;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class DriverRegistry
{
    public const string AUTO = 'auto';

    public const array DRIVERS = [ClaudeCodeDriver::NAME, OpenCodeDriver::NAME];

    private const array ALIASES = ['claude' => ClaudeCodeDriver::NAME];

    public function __construct(
        private readonly AuditConfig $config,
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
        $name = $this->normalize($name ?? $this->config->string('agent.driver'));

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

        return $this->make(Arr::first($candidates), $model);
    }

    private function make(string $name, ?string $model): AgentDriver
    {
        $model = $this->filled($model) ?? $this->config->nullableString('agent.model');
        $boost = McpServer::forProject($this->project);
        $boostTools = $this->config->strings('agent.boost_tools');
        $deniedFiles = DeniedFiles::resolve(
            $this->project->basePath,
            $this->config->strings('agent.deny_read'),
            $this->config->strings('agent.allow_read'),
        );

        return match ($name) {
            OpenCodeDriver::NAME => new OpenCodeDriver(
                basePath: $this->project->basePath,
                binary: $this->binary($name),
                model: $model,
                maxTurns: $this->maxTurns(),
                verifyMaxTurns: $this->maxTurns('agent.verify_max_turns'),
                boost: $boost,
                boostTools: $boostTools,
                deniedFiles: $deniedFiles,
                taskSteps: AuditDefinition::turnCaps(),
            ),
            default => new ClaudeCodeDriver(
                binary: $this->binary($name),
                model: $model,
                effort: $this->config->nullableString("agent.drivers.{$name}.effort"),
                maxTurns: $this->maxTurns(),
                verifyMaxTurns: $this->maxTurns('agent.verify_max_turns'),
                maxBudgetUsd: $this->config->nullableFloat("agent.drivers.{$name}.max_budget_usd"),
                boost: $boost,
                boostTools: $boostTools,
                deniedFiles: $deniedFiles,
            ),
        };
    }

    private function maxTurns(string $key = 'agent.max_turns'): ?int
    {
        $turns = $this->config->int($key);

        return $turns > 0 ? $turns : null;
    }

    private function binary(string $name): string
    {
        return $this->config->nullableString("agent.drivers.{$name}.binary") ?? match ($name) {
            ClaudeCodeDriver::NAME => 'claude',
            default => $name,
        };
    }

    private function normalize(string $name): string
    {
        $name = strtolower(trim($name));

        return Arr::get(self::ALIASES, $name) ?? ($name === '' ? self::AUTO : $name);
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
