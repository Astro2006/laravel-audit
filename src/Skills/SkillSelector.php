<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Skills;

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\TaskLog;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Closure;
use Illuminate\Support\Facades\Process;
use Throwable;

final class SkillSelector
{
    public const string KEY = 'skills';

    private const string TEMPLATE = 'shared/skills.md';

    private const int DESCRIPTION_CHARS = 100;

    public function __construct(private readonly AuditConfig $config) {}

    /**
     * The skills of every task of this run: from the config, from an earlier
     * run with the same skills and tasks, or from one agent call.
     *
     * @param  list<AuditTask>  $tasks
     * @param  (Closure(): void)|null  $onAsk  called right before the agent call
     * @return array{SkillSelection, list<string>} the selection and warnings
     */
    public function select(array $tasks, SkillInventory $inventory, AgentDriver $driver, AuditRun $run, ProjectInspector $project, bool $reselect = false, ?Closure $onAsk = null): array
    {
        $taskKeys = array_map(fn (AuditTask $task): string => $task->key(), $tasks);
        $tasksHash = self::tasksHash($tasks);
        $mode = $this->config->get('agent.skills');
        $perTask = max(1, $this->config->int('skills_per_task'));

        if ($inventory->isEmpty() || $mode === 'none' || $tasks === []) {
            return [new SkillSelection([], $inventory->hash(), $tasksHash), []];
        }

        if (is_array($mode)) {
            [$selection, $dropped] = SkillSelection::validate($mode, $taskKeys, $inventory, $perTask);

            return [new SkillSelection($selection, $inventory->hash(), $tasksHash, 'config'), self::warnings($dropped)];
        }

        $cached = $reselect ? null : self::cached($run, $inventory->hash(), $tasksHash);

        if ($cached !== null) {
            return [new SkillSelection($cached->tasks, $inventory->hash(), $tasksHash, 'cache'), []];
        }

        $onAsk?->__invoke();

        try {
            $answer = $this->ask($tasks, $inventory, $perTask, $driver, $run, $project);
        } catch (Throwable $exception) {
            return [new SkillSelection([], $inventory->hash(), $tasksHash), ['Skill selection failed ('.$exception->getMessage().'); the tasks run without skills.']];
        }

        if ($answer === null) {
            return [new SkillSelection([], $inventory->hash(), $tasksHash), ['The skill selection answer was no JSON object; the tasks run without skills.']];
        }

        [$selection, $dropped] = SkillSelection::validate($answer, $taskKeys, $inventory, $perTask);

        return [new SkillSelection($selection, $inventory->hash(), $tasksHash, 'agent'), self::warnings($dropped)];
    }

    /** @param  list<AuditTask>  $tasks */
    public static function prompt(array $tasks, SkillInventory $inventory, int $perTask): string
    {
        return strtr((new PromptTemplates(PromptTemplates::publishedPath()))->get(self::TEMPLATE), [
            '{{tasks}}' => implode("\n", array_map(fn (AuditTask $task): string => sprintf('- %s (%s): %s', $task->key(), $task->title, implode(', ', $task->checks)), $tasks)),
            '{{skills}}' => implode("\n", array_map(fn (Skill $skill): string => sprintf('- %s: %s', $skill->name, $skill->summary(self::DESCRIPTION_CHARS)), $inventory->skills)),
            '{{per_task}}' => (string) $perTask,
        ]);
    }

    /** @param  list<AuditTask>  $tasks */
    public static function tasksHash(array $tasks): string
    {
        return substr(sha1((string) json_encode(array_map(fn (AuditTask $task): array => [$task->key(), $task->checks], $tasks))), 0, 12);
    }

    /**
     * @param  list<AuditTask>  $tasks
     * @return array<mixed>|null
     */
    private function ask(array $tasks, SkillInventory $inventory, int $perTask, AgentDriver $driver, AuditRun $run, ProjectInspector $project): ?array
    {
        $prompt = self::prompt($tasks, $inventory, $perTask);

        if (! is_dir($run->logsDir())) {
            mkdir($run->logsDir(), 0777, true);
        }

        file_put_contents($run->logsDir().'/'.self::KEY.'.prompt.md', $prompt);
        $request = new AgentRequest(self::KEY, $prompt, $project->basePath, $driver->writeConfig($run->logsDir()), answerOnly: true);

        $result = Process::path($project->basePath)
            ->timeout($this->config->int('agent.timeout'))
            ->env([...ChildEnvironment::forApp($project)->overrides(), ...$driver->environment($request), 'AUDIT_TASK' => self::KEY])
            ->input($prompt)
            ->run($driver->command($request));

        (new TaskLog($run->logsDir()))->write(self::KEY, $result->output(), $result->errorOutput());
        $answer = $driver->parse($result->output(), (int) $result->exitCode())->answer;
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $json = json_decode(substr($answer, $start, $end - $start + 1), true);

        return is_array($json) ? $json : null;
    }

    private static function cached(AuditRun $run, string $inventoryHash, string $tasksHash): ?SkillSelection
    {
        $files = glob($run->outputRoot.'/*/'.SkillSelection::FILE) ?: [];
        rsort($files);

        foreach ($files as $file) {
            $selection = SkillSelection::read($file);

            if ($selection !== null && $selection->source !== 'none' && $selection->inventoryHash === $inventoryHash && $selection->tasksHash === $tasksHash) {
                return $selection;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $dropped
     * @return list<string>
     */
    private static function warnings(array $dropped): array
    {
        return $dropped === [] ? [] : ['Skill selection: dropped '.implode('; ', $dropped).'.'];
    }
}
