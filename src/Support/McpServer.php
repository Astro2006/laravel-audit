<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

/**
 * The Laravel Boost MCP server as a stdio command. Each driver renders it in
 * its CLI's own config format.
 */
final readonly class McpServer
{
    public const string NAME = 'laravel-boost';

    /** Boost tools that execute code, query data, read logs or return config values. */
    public const array DENIED_TOOLS = ['tinker', 'database-query', 'read-log-entries', 'last-error', 'browser-logs', 'list-available-env-vars', 'get-config'];

    /**
     * @param  array<mixed>  $config  the entry as found in .mcp.json (command, args, env, …)
     */
    public function __construct(
        public string $name,
        public array $config,
    ) {}

    /** The default entry Boost itself writes: php artisan boost:mcp. */
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
