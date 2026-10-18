<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

final readonly class McpServer
{
    public const string NAME = 'laravel-boost';

    public const array DENIED_TOOLS = ['tinker', 'database-query', 'read-log-entries', 'last-error', 'browser-logs', 'list-available-env-vars', 'get-config'];

    /**
     * @param  array<mixed>  $config  the entry as found in .mcp.json (command, args, env, …)
     */
    public function __construct(
        public string $name,
        public array $config,
    ) {}

    public static function forProject(ProjectInspector $project): ?self
    {
        if (! $project->isInstalled('laravel/boost')) {
            return null;
        }

        foreach (Read::array(Json::readFile($project->path('.mcp.json')), 'mcpServers') as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            if ($name === McpServer::NAME || str_contains(implode(' ', Read::strings($config, 'args')), 'boost:mcp')) {
                return new self((string) $name, $config);
            }
        }

        return self::default();
    }

    public static function default(): self
    {
        return new self(self::NAME, ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]);
    }

    public function command(): string
    {
        return Read::string($this->config, 'command', 'php');
    }

    /** @return list<string> */
    public function args(): array
    {
        return Read::strings($this->config, 'args');
    }

    /** @return array<string, string> */
    public function env(): array
    {
        return Read::stringMap($this->config, 'env');
    }

    /**
     * The configured tools minus the ones an agent must never get.
     *
     * @param  list<string>  $configured
     * @return list<string>
     */
    public static function allowedTools(array $configured): array
    {
        return array_values(array_diff($configured, self::DENIED_TOOLS));
    }
}
