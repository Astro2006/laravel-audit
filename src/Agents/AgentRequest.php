<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

final readonly class AgentRequest
{
    /**
     * @param  string  $key  task key, e.g. backend.injection
     * @param  string  $workingDirectory  project root; the agent can only read below it
     * @param  string|null  $configFile  the driver's per-run config (see AgentDriver::writeConfig())
     * @param  list<string>  $extraDirectories  directories outside the project the agent may read
     * @param  array<string, string>  $environment  extra variables for the agent process
     * @param  bool  $verify  a verify call, which gets fewer turns
     */
    public function __construct(
        public string $key,
        public string $prompt,
        public string $workingDirectory,
        public ?string $configFile = null,
        public array $extraDirectories = [],
        public array $environment = [],
        public bool $verify = false,
    ) {}
}
