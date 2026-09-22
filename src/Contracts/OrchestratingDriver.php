<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Agents\AgentRequest;

interface OrchestratingDriver extends AgentDriver
{
    public const string TOOL_COMMAND = 'php artisan audit:tool *';

    /**
     * Writes the orchestrator's CLI config into $directory and returns its path.
     *
     * @param  list<string>  $extraDirectories  directories outside the project the agent may read
     * @param  int|null  $maxTurns  tool calls before the orchestrator must answer
     */
    public function writeOrchestratorConfig(string $directory, array $extraDirectories = [], ?int $maxTurns = null): string;

    /** @return list<string> argv */
    public function orchestratorCommand(AgentRequest $request): array;
}
