<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Dotenv\Dotenv;

/**
 * `php artisan audit` has loaded the app's .env into its own process
 * environment, and child processes inherit it. A test run would then keep
 * APP_ENV=local or the app's DB_DATABASE, because phpunit.xml <env> without
 * force="true" does not override existing variables. Child processes
 * therefore start without the variables that came from .env, like a fresh
 * shell, and resolve their own environment (.env.testing, phpunit.xml).
 */
final readonly class ChildEnvironment
{
    /** @param list<string> $dotenvNames */
    public function __construct(
        public array $dotenvNames = [],
    ) {}

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
     * Environment for child processes: every .env variable is removed.
     *
     * @return array<string, false>
     */
    public function overrides(): array
    {
        return array_fill_keys($this->dotenvNames, false);
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
