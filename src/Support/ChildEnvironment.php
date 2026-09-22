<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Dotenv\Dotenv;

final readonly class ChildEnvironment
{
    public const array AGENT_VARIABLES = [
        'AI_AGENT', 'CLAUDECODE', 'CLAUDE_CODE', 'OPENCODE', 'OPENCODE_CLIENT', 'CODEX_SANDBOX', 'CODEX_THREAD_ID',
        'CURSOR_AGENT', 'CURSOR_TRACE_ID', 'GEMINI_CLI', 'AUGMENT_AGENT', 'AMP_CURRENT_THREAD_ID', 'REPL_ID',
    ];

    /** @param list<string> $dotenvNames */
    public function __construct(
        public array $dotenvNames = [],
    ) {}

    public static function forApp(ProjectInspector $project): self
    {
        return self::for($project, app()->environmentFile());
    }

    public static function for(ProjectInspector $project, string $environmentFile = '.env'): self
    {
        $path = $project->path($environmentFile);

        if (! is_file($path)) {
            return new self;
        }

        try {
            return new self(array_keys(Dotenv::parse((string) file_get_contents($path))));
        } catch (\Throwable) {
            return new self;
        }
    }

    /**
     * Environment for child processes: every .env variable and every
     * agent marker is removed.
     *
     * @return array<string, false>
     */
    public function overrides(): array
    {
        return array_fill_keys([...$this->dotenvNames, ...self::AGENT_VARIABLES], false);
    }

    /**
     * Variables a child process still inherits: exported in the shell or by
     * CI, not loaded from .env. They win over phpunit.xml <env> without force.
     *
     * @return array<string, string>
     */
    public function inherited(): array
    {
        return array_diff_key(getenv(), array_flip($this->dotenvNames));
    }
}
