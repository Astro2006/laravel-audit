<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

use Astro2006\LaravelAudit\Contracts\OrchestratingDriver;
use Astro2006\LaravelAudit\Support\McpServer;

final readonly class OpenCodeConfig
{
    private const array TOOLS = ['read', 'glob', 'list'];

    private const array DENIED_PERMISSIONS = ['edit', 'bash', 'webfetch', 'websearch', 'task', 'skill', 'question', 'lsp', 'doom_loop'];

    /**
     * @param  string  $basePath  project root; deny rules match absolute paths too
     * @param  list<string>  $boostTools  allowed Boost MCP tools
     * @param  list<string>  $deniedFiles  project-relative files the agent must not read
     * @param  int|null  $steps  tool-call rounds before the agent must answer
     */
    public function __construct(
        private string $basePath,
        private ?McpServer $boost,
        private array $boostTools,
        private array $deniedFiles,
        private ?int $steps = null,
    ) {}

    /**
     * @param  list<string>  $extraDirectories  directories outside the project the agent may read
     * @return array<string, mixed>
     */
    public function toArray(array $extraDirectories): array
    {
        $config = [
            '$schema' => 'https://opencode.ai/config.json',
            'agent' => [
                OpenCodeDriver::AGENT => [
                    'description' => 'Read-only Laravel audit agent',
                    'mode' => 'primary',
                    'tools' => $this->tools(),
                    'permission' => $this->permission($extraDirectories),
                    ...($this->steps === null ? [] : ['steps' => $this->steps]),
                ],
            ],
        ];

        if ($this->boost !== null) {
            $config['mcp'] = [
                McpServer::NAME => array_filter([
                    'type' => 'local',
                    'command' => [$this->boost->command(), ...$this->boost->args()],
                    'environment' => $this->boost->env() === [] ? null : $this->boost->env(),
                    'enabled' => true,
                ], fn (mixed $value): bool => $value !== null),
            ];
        }

        return $config;
    }

    /**
     * The config of the orchestrator: read, glob and list like the audit
     * agents, plus a shell that runs `php artisan audit:tool …` only.
     *
     * @param  list<string>  $extraDirectories
     * @param  int|null  $steps  tool-call rounds before the orchestrator must answer
     * @return array<string, mixed>
     */
    public function orchestrator(array $extraDirectories, ?int $steps): array
    {
        $tools = ['*' => false, 'bash' => true];

        foreach (self::TOOLS as $tool) {
            $tools[$tool] = true;
        }

        return [
            '$schema' => 'https://opencode.ai/config.json',
            'agent' => [
                OpenCodeDriver::ORCHESTRATOR_AGENT => [
                    'description' => 'Orchestrates a Laravel audit through php artisan audit:tool',
                    'mode' => 'primary',
                    'tools' => $tools,
                    'permission' => [
                        ...$this->permission($extraDirectories),
                        'bash' => ['*' => 'deny', OrchestratingDriver::TOOL_COMMAND => 'allow'],
                    ],
                    ...($steps === null ? [] : ['steps' => $steps]),
                ],
            ],
        ];
    }

    /** @return array<string, bool> */
    private function tools(): array
    {
        $tools = ['*' => false];

        foreach (self::TOOLS as $tool) {
            $tools[$tool] = true;
        }

        if ($this->boost !== null) {
            foreach (McpServer::allowedTools($this->boostTools) as $tool) {
                $tools[McpServer::NAME.'_'.$tool] = true;
            }
        }

        return $tools;
    }

    /**
     * @param  list<string>  $extraDirectories
     * @return array<string, mixed>
     */
    private function permission(array $extraDirectories): array
    {
        $permission = array_fill_keys(self::DENIED_PERMISSIONS, 'deny');
        $permission['read'] = ['*' => 'allow'];

        foreach ($this->deniedFiles as $file) {
            $file = str_starts_with($file, './') ? substr($file, 2) : $file;
            $permission['read'][$file] = 'deny';
            $permission['read'][rtrim($this->basePath, '/').'/'.$file] = 'deny';
        }

        $permission['external_directory'] = ['*' => 'deny'];

        foreach ($extraDirectories as $extra) {
            $permission['external_directory'][rtrim($extra, '/').'/*'] = 'allow';
        }

        return $permission;
    }
}
