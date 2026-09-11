<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\AgentResult;

/**
 * Starts an agent CLI headless. The prompt always goes through STDIN.
 */
interface AgentDriver
{
    public function name(): string;

    public function isAvailable(): bool;

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
    public function environment(): array;
}
