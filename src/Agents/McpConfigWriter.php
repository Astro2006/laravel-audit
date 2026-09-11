<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Writes an MCP config that contains only the Boost server, so the user's
 * other MCP servers never load into an audit.
 */
final class McpConfigWriter
{
    public function write(ProjectInspector $project, string $path): ?string
    {
        $server = $project->boostMcpServer();

        if ($server === null) {
            return null;
        }

        // Relative commands like "php artisan boost:mcp" work because the
        // agent runs with the project root as working directory.
        Json::writeFile($path, ['mcpServers' => [ClaudeCodeDriver::MCP_SERVER => $server->config]]);

        return $path;
    }
}
