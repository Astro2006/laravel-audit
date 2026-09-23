<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;

interface AgentDriver
{
    public function name(): string;

    public function displayName(): string;

    public function binary(): string;

    public function model(): ?string;

    public function isAvailable(): bool;

    public function toolsNote(): string;

    /**
     * Writes the per-run CLI config (MCP server, permissions) into $directory.
     * Returns its path, or null when the driver needs no file.
     *
     * @param  list<string>  $extraDirectories  directories outside the project the agent may read
     */
    public function writeConfig(string $directory, array $extraDirectories = []): ?string;

    /** @return list<string> argv */
    public function command(AgentRequest $request): array;

    /**
     * Continues the previous session (used for the repair pass).
     *
     * @return list<string> argv
     */
    public function resumeCommand(AgentResult $previous, AgentRequest $request): array;

    public function parse(string $stdout, int $exitCode): AgentResult;

    /**
     * Environment overrides for the agent process; false removes a variable.
     *
     * @return array<string, string|false>
     */
    public function environment(AgentRequest $request): array;
}
