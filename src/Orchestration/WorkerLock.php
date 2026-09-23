<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

final class WorkerLock
{
    public const string PREFLIGHT = 'preflight';

    public const string TASKS = 'tasks';

    private const int START_GRACE_SECONDS = 30;

    public function __construct(private readonly string $path) {}

    /**
     * @param  list<string>  $items  preflight steps or task keys
     */
    public function acquire(string $kind, array $items): bool
    {
        if ($this->current() !== null) {
            return false;
        }

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0777, true);
        }

        $handle = @fopen($this->path, 'x');

        if ($handle === false) {
            return false;
        }

        fwrite($handle, (string) json_encode(['kind' => $kind, 'items' => $items, 'pid' => null, 'started_at' => time()]));
        fclose($handle);

        return true;
    }

    public function claim(int $pid): void
    {
        $lock = $this->current();

        if ($lock !== null) {
            Json::writeFile($this->path, [...$lock, 'pid' => $pid]);
        }
    }

    /**
     * The running worker, or null. A lock whose process is gone is removed.
     *
     * @return array{kind: string, items: list<string>, pid: int|null, started_at: int}|null
     */
    public function current(): ?array
    {
        $data = Json::readFile($this->path);

        if ($data === []) {
            return null;
        }

        $pid = Read::nullableInt($data, 'pid');
        $startedAt = Read::int($data, 'started_at');
        $stale = $pid === null
            ? time() - $startedAt > self::START_GRACE_SECONDS
            : ! self::alive($pid);

        if ($stale) {
            $this->release();

            return null;
        }

        return ['kind' => Read::string($data, 'kind'), 'items' => Read::strings($data, 'items'), 'pid' => $pid, 'started_at' => $startedAt];
    }

    public function release(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private static function alive(int $pid): bool
    {
        if ($pid === getmypid()) {
            return true;
        }

        return function_exists('posix_kill') ? posix_kill($pid, 0) : true;
    }
}
