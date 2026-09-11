<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Arr;

final class PreflightContext
{
    /** @var array<string, StepResult> */
    private array $results = [];

    /**
     * @param  array<string, mixed>  $config  the "audit" config array
     */
    public function __construct(
        public readonly ProjectInspector $project,
        public readonly string $preflightDir,
        public readonly string $logsDir,
        public readonly array $config,
        public readonly RunSelection $selection,
        public readonly string $phpBinary = PHP_BINARY,
    ) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    public function record(StepResult $result): void
    {
        $this->results[$result->step->value] = $result;
    }

    public function result(PreflightStep $step): ?StepResult
    {
        return $this->results[$step->value] ?? null;
    }

    /** @return array<string, StepResult> */
    public function results(): array
    {
        return $this->results;
    }

    /** Absolute path below the preflight directory; parent directories are created. */
    public function path(string $relative): string
    {
        $path = $this->preflightDir.'/'.ltrim($relative, '/');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        return $path;
    }

    public function logPath(string $file): string
    {
        if (! is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0777, true);
        }

        return $this->logsDir.'/'.$file;
    }

    public function writeJson(string $relative, mixed $data): string
    {
        $path = $this->path($relative);
        Json::writeFile($path, $data);

        return $path;
    }

    /** @return array<mixed> */
    public function readJson(string $relative): array
    {
        return Json::readFile($this->preflightDir.'/'.ltrim($relative, '/'));
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function artisan(array $arguments): array
    {
        return [$this->phpBinary, 'artisan', ...$arguments];
    }

    /**
     * A PHP script from vendor/bin, started through PHP so it also works on Windows.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function vendorBinary(string $name, array $arguments): array
    {
        return [$this->phpBinary, $this->project->path('vendor/bin/'.$name), ...$arguments];
    }

    /** Last lines of a tool's output, for error messages. */
    public static function tail(string $output, int $lines = 15): string
    {
        $all = preg_split('/\R/', trim($output)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
