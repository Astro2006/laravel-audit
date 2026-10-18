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

    /** @param array<string, string> $dotenv  the variables in the app's .env file, name => value */
    public function __construct(
        public array $dotenv = [],
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
            return new self(array_map(fn (?string $value): string => (string) $value, Dotenv::parse((string) file_get_contents($path))));
        } catch (\Throwable) {
            return new self;
        }
    }

    /**
     * Environment for agent CLIs: every variable named in .env and every
     * agent marker is removed, whatever its origin, so no secret reaches them.
     *
     * @return array<string, false>
     */
    public function overrides(): array
    {
        return array_fill_keys([...array_keys($this->dotenv), ...self::AGENT_VARIABLES], false);
    }

    /**
     * Environment for the preflight tools: like a fresh shell. Variables this
     * process loaded from .env are removed; a variable exported in the shell
     * keeps its value, because Laravel never lets .env overwrite it.
     *
     * @return array<string, false>
     */
    public function toolOverrides(): array
    {
        return array_fill_keys([...$this->loadedFromDotenv(), ...self::AGENT_VARIABLES], false);
    }

    /**
     * Variables a child process still inherits: exported in the shell or by
     * CI, not loaded from .env. They win over phpunit.xml <env> without force.
     *
     * @return array<string, string>
     */
    public function inherited(): array
    {
        return array_diff_key(getenv(), array_flip($this->loadedFromDotenv()));
    }

    /**
     * A variable that holds its .env value came from .env, or was exported
     * with the same value, which makes no difference to the child.
     *
     * @return list<string>
     */
    private function loadedFromDotenv(): array
    {
        return array_keys(array_filter(
            $this->dotenv,
            fn (string $value, string $name): bool => ! in_array(getenv($name), [false, ''], true) ? getenv($name) === $value : true,
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
