<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

final class Attempts
{
    public function __construct(private readonly string $directory) {}

    public function of(string $key): int
    {
        return Read::int(Json::readFile($this->path($key)), 'attempts');
    }

    /** @param  list<string>  $keys  tasks that start now */
    public function count(array $keys): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        foreach ($keys as $key) {
            Json::writeFile($this->path($key), ['attempts' => $this->of($key) + 1]);
        }
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.$key.'.json';
    }
}
