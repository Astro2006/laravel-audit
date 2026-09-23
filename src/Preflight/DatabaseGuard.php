<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;
use Dotenv\Dotenv;
use Illuminate\Support\Arr;

final class DatabaseGuard
{
    private const array URL_DRIVERS = ['mysql2' => 'mysql', 'postgres' => 'pgsql', 'postgresql' => 'pgsql', 'sqlite3' => 'sqlite', 'mssql' => 'sqlsrv'];

    /**
     * @param  array<mixed>  $connections  config('database.connections')
     * @param  string  $appConnection  config('database.default')
     * @param  array<string, string>  $inherited  variables the test process inherits, see ChildEnvironment
     */
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly array $connections,
        private readonly string $appConnection,
        private readonly array $inherited = [],
    ) {}

    public static function forApp(ProjectInspector $project): self
    {
        return new self(
            $project,
            (array) config('database.connections', []),
            (string) config('database.default', 'sqlite'),
            ChildEnvironment::forApp($project)->inherited(),
        );
    }

    public function check(): DatabaseGuardResult
    {
        $app = $this->describe($this->appConnection, []);

        $env = $this->testEnv();
        $test = $this->describe(Read::string($env, 'DB_CONNECTION', $this->appConnection), $env);

        if ($test->isInMemorySqlite()) {
            return new DatabaseGuardResult(true, $test->label, $app->label);
        }

        $shared = $test->driver === $app->driver && $this->sameDatabase($test, $app);

        return new DatabaseGuardResult(! $shared, $test->label, $app->label);
    }

    /** @param array<string, string> $env */
    private function describe(string $name, array $env): ConnectionInfo
    {
        $config = Read::array($this->connections, $name);
        $driver = Read::string($config, 'driver', $name);
        $database = Read::string($env, 'DB_DATABASE', Read::string($config, 'database'));
        $host = Read::string($env, 'DB_HOST', Read::string($config, 'host'));
        $port = Read::string($env, 'DB_PORT', (string) Read::int($config, 'port'));
        $url = parse_url(Read::string($env, 'DB_URL', Read::string($config, 'url')));

        if (is_array($url) && Arr::has($url, 'scheme')) {
            $driver = (string) Arr::get(self::URL_DRIVERS, (string) Arr::get($url, 'scheme'), Arr::get($url, 'scheme'));
            $host = (string) Arr::get($url, 'host', $host);
            $port = (string) Arr::get($url, 'port', $port);
            $database = Arr::has($url, 'path') ? substr((string) Arr::get($url, 'path'), 1) : $database;
        }

        if ($driver === 'sqlite' && $database === '') {
            $database = $this->project->path('database/database.sqlite');
        }

        $label = $driver === 'sqlite'
            ? 'sqlite '.($database === ':memory:' ? ':memory:' : $this->project->relative($database))
            : sprintf('%s %s@%s', $driver, $database, $host ?: 'localhost');

        return new ConnectionInfo($driver, $host, $database, $label, $port === '0' ? '' : $port);
    }

    private function sameDatabase(ConnectionInfo $a, ConnectionInfo $b): bool
    {
        if ($a->driver === 'sqlite') {
            $pathA = $this->absolute($a->database);
            $pathB = $this->absolute($b->database);

            return (realpath($pathA) ?: $pathA) === (realpath($pathB) ?: $pathB);
        }

        return strcasecmp($a->database, $b->database) === 0
            && $this->normalizeHost($a->host) === $this->normalizeHost($b->host)
            && ($a->port === '' || $b->port === '' || $a->port === $b->port);
    }

    /**
     * The variables the test process ends up with. PHPUnit sets <env> before
     * Laravel boots (without force only where the shell has no value), then
     * Laravel loads `.env.<APP_ENV>` if it exists, else `.env`, without
     * overwriting anything already set.
     *
     * @return array<string, string>
     */
    private function testEnv(): array
    {
        $phpunit = $this->project->phpunit();
        $set = [...$phpunit->env(), ...$this->inherited, ...$phpunit->env(forcedOnly: true)];
        $appEnv = Read::string($set, 'APP_ENV');
        $file = $appEnv !== '' && is_file($this->project->path('.env.'.$appEnv)) ? '.env.'.$appEnv : '.env';

        return [...$this->fileEnv($file), ...$set];
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
