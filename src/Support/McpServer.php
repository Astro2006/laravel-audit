<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

/**
 * An MCP server entry from .mcp.json. The config is passed through unchanged.
 */
final readonly class McpServer
{
    /** @param array<mixed> $config */
    public function __construct(
        public string $name,
        public array $config,
    ) {}
}
