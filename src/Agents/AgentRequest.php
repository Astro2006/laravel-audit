<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

final readonly class AgentRequest
{
    /**
     * @param  string  $workingDirectory  project root; the agent can only read below it
     * @param  string|null  $mcpConfig  MCP config file for this run, or null for no MCP servers
     * @param  list<string>  $extraDirectories  directories outside the project the agent may read
     */
    public function __construct(
        public string $key,
        public string $prompt,
        public string $workingDirectory,
        public ?string $mcpConfig = null,
        public array $extraDirectories = [],
    ) {}
}
