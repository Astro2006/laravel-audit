<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\RunPlan;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

final class PreflightContext
{
    public readonly StepResults $results;

    public function __construct(
        public readonly ProjectInspector $project,
        public readonly string $preflightDir,
        public readonly string $logsDir,
        public readonly AuditConfig $config,
        public readonly RunSelection $selection,
        public readonly ChildEnvironment $environment = new ChildEnvironment,
        public readonly string $phpBinary = PHP_BINARY,
    ) {
        $this->results = new StepResults;
    }

    public static function forRun(RunPlan $plan, AuditRun $run, AuditConfig $config, ?RunSelection $selection = null): self
    {
        return new self($plan->project, $run->preflightDir(), $run->logsDir(), $config, $selection ?? $plan->selection, ChildEnvironment::forApp($plan->project));
    }

    /**
     * A process in the project directory, without the variables the audit
     * process loaded from .env.
     *
     * @param  array<string, string|false>  $environment
     */
    public function process(array $environment = []): PendingProcess
    {
        return Process::path($this->project->basePath)->env([...$this->environment->toolOverrides(), ...$environment]);
    }

    public function record(StepResult $result): void
    {
        $this->results->put($result);
    }

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

    /** @param Arrayable<array-key, mixed>|array<mixed> $data */
    public function writeJson(string $relative, Arrayable|array $data): string
    {
        $path = $this->path($relative);
        Json::writeFile($path, $data instanceof Arrayable ? $data->toArray() : $data);

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

    public static function tail(string $output, int $lines = 15): string
    {
        $all = Regex::lines(trim($output));

        return implode("\n", array_slice($all, -$lines));
    }
}
