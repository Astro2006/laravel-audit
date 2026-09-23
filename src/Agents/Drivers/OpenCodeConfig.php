<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

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
        private ?int $verifySteps = null,
    ) {}

    /**
     * @param  list<string>  $extraDirectories  directories outside the project the agent may read
     * @return array<string, mixed>
     */
    public function toArray(array $extraDirectories): array
    {
        $agent = fn (string $description, ?int $steps): array => [
            'description' => $description,
            'mode' => 'primary',
            'tools' => $this->tools(),
            'permission' => $this->permission($extraDirectories),
            ...($steps === null ? [] : ['steps' => $steps]),
        ];

        $config = [
            '$schema' => 'https://opencode.ai/config.json',
            'agent' => [
                OpenCodeDriver::AGENT => $agent('Read-only Laravel audit agent', $this->steps),
                OpenCodeDriver::VERIFY_AGENT => $agent('Read-only agent that verifies Laravel audit findings', $this->verifySteps ?? $this->steps),
                OpenCodeDriver::ANSWER_AGENT => [
                    ...$agent('Answers from the prompt alone, without tools', 1),
                    'tools' => ['*' => false],
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
