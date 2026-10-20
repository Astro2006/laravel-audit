<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final class ModelProbe
{
    public const string KEY = 'model-check';

    private const string PROMPT = 'Reply with the single word OK.';

    private const int LIST_TIMEOUT = 30;

    /** @param  int  $timeout  seconds the model may take to answer; a local model may have to load first */
    public function __construct(
        private readonly ProjectInspector $project,
        private readonly int $timeout = 180,
    ) {}

    /** @return string|null why the driver's model did not answer one tool-less turn; null when it did or a usage limit stopped it, which the run handles itself */
    public function check(AgentDriver $driver): ?string
    {
        $directory = sys_get_temp_dir().'/laravel-audit-'.self::KEY.'-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);

        try {
            $request = new AgentRequest(self::KEY, self::PROMPT, $this->project->basePath, $driver->writeConfig($directory), answerOnly: true);
            $result = Process::path($this->project->basePath)
                ->timeout($this->timeout)
                ->env([...ChildEnvironment::forApp($this->project)->overrides(), ...$driver->environment($request), 'AUDIT_TASK' => self::KEY])
                ->input(self::PROMPT)
                ->run($driver->command($request));
        } catch (ProcessTimedOutException) {
            return sprintf('No answer within %d seconds.', $this->timeout);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }

        $parsed = $driver->parse($result->output(), (int) $result->exitCode());

        if ($parsed->exit === AgentExit::Success || $parsed->exit === AgentExit::Limit) {
            return null;
        }

        return $parsed->error ?? trim($parsed->exit->value.' '.mb_substr(trim($result->errorOutput()), 0, 300));
    }

    /** @return list<string> the models the driver's CLI offers, without the one that failed */
    public function alternatives(AgentDriver $driver): array
    {
        $command = $driver->modelsCommand();

        try {
            $listed = $command === null ? '' : Process::path($this->project->basePath)->timeout(self::LIST_TIMEOUT)->run($command)->output();
        } catch (ProcessTimedOutException) {
            $listed = '';
        }

        return array_values(array_filter($driver->models($listed), fn (string $model): bool => $model !== $driver->model()));
    }
}
