<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Support\ProjectInspector;
use Dotenv\Dotenv;

/**
 * Tests with RefreshDatabase wipe the database they run against. Before any
 * test runs, the guard resolves the test connection the way Laravel does
 * (.env.testing replaces .env, phpunit.xml <env> wins) and compares it with
 * the app database. When in doubt, it says "not safe".
 */
final class DatabaseGuard
{
    /**
     * @param  array<string, array<string, mixed>>  $connections  config('database.connections')
     * @param  string  $appConnection  config('database.default')
     */
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly array $connections,
        private readonly string $appConnection,
    ) {}

    public function check(): DatabaseGuardResult
    {
        // The running app already resolved its own connection from the environment.
        $app = $this->describe($this->appConnection, $this->connections[$this->appConnection] ?? [], []);

        $env = $this->testEnv();
        $testName = $env['DB_CONNECTION'] ?? $this->appConnection;
        $test = $this->describe($testName, $this->connections[$testName] ?? [], $env);

        if ($test['driver'] === 'sqlite' && $test['database'] === ':memory:') {
            return new DatabaseGuardResult(true, $test['label'], $app['label']);
        }

        $shared = $test['driver'] === $app['driver']
            && $this->sameDatabase($test, $app);

        return new DatabaseGuardResult(! $shared, $test['label'], $app['label']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $env
     * @return array{driver: string, host: string, database: string, label: string}
     */
    private function describe(string $name, array $config, array $env): array
    {
        $driver = (string) ($config['driver'] ?? $name);
        $database = (string) ($env['DB_DATABASE'] ?? $config['database'] ?? '');
        $host = (string) ($env['DB_HOST'] ?? $config['host'] ?? '');

        if ($driver === 'sqlite' && $database === '') {
            $database = $this->project->path('database/database.sqlite');
        }

        $label = $driver === 'sqlite'
            ? 'sqlite '.($database === ':memory:' ? ':memory:' : $this->project->relative($database))
            : sprintf('%s %s@%s', $driver, $database, $host ?: 'localhost');

        return ['driver' => $driver, 'host' => $host, 'database' => $database, 'label' => $label];
    }

    /**
     * @param  array{driver: string, host: string, database: string}  $a
     * @param  array{driver: string, host: string, database: string}  $b
     */
    private function sameDatabase(array $a, array $b): bool
    {
        if ($a['driver'] === 'sqlite') {
            $pathA = $this->absolute($a['database']);
            $pathB = $this->absolute($b['database']);

            return (realpath($pathA) ?: $pathA) === (realpath($pathB) ?: $pathB);
        }

        return strcasecmp($a['database'], $b['database']) === 0
            && $this->normalizeHost($a['host']) === $this->normalizeHost($b['host']);
    }

    /** @return array<string, string> */
    private function testEnv(): array
    {
        $env = is_file($this->project->path('.env.testing'))
            ? $this->fileEnv('.env.testing')
            : $this->fileEnv('.env');

        return [...$env, ...$this->project->phpunitEnv()];
    }

    /** @return array<string, string> */
    private function fileEnv(string $file): array
    {
        $path = $this->project->path($file);

        if (! is_file($path)) {
            return [];
        }

        try {
            return array_map(fn (?string $value): string => (string) $value, Dotenv::parse((string) file_get_contents($path)));
        } catch (\Throwable) {
            return [];
        }
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : $this->project->path($path);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true) ? 'localhost' : $host;
    }
}
