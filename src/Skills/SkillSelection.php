<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Skills;

use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final readonly class SkillSelection
{
    public const string FILE = 'skills.json';

    /**
     * @param  array<string, list<string>>  $tasks  task key => skill names
     * @param  string  $source  agent, config, cache or none
     */
    public function __construct(
        public array $tasks = [],
        public string $inventoryHash = '',
        public string $tasksHash = '',
        public string $source = 'none',
    ) {}

    /** @return list<string> */
    public function for(string $taskKey): array
    {
        return Arr::get($this->tasks, $taskKey, []);
    }

    /**
     * Keeps only known tasks and installed skills, at most $perTask per task.
     *
     * @param  array<mixed>  $answer  task key => skill names, as a model or the config gave it
     * @param  list<string>  $taskKeys
     * @return array{array<string, list<string>>, list<string>} the selection and what was dropped
     */
    public static function validate(array $answer, array $taskKeys, SkillInventory $inventory, int $perTask): array
    {
        $selection = [];
        $dropped = [];

        foreach ($answer as $key => $names) {
            if (! in_array($key, $taskKeys, true)) {
                $dropped[] = sprintf('unknown task "%s"', $key);

                continue;
            }

            $valid = [];

            foreach (is_array($names) ? $names : [$names] as $name) {
                if (is_string($name) && $inventory->has($name) && ! in_array($name, $valid, true)) {
                    $valid[] = $name;
                } else {
                    $dropped[] = sprintf('unknown skill "%s" for %s', is_scalar($name) ? (string) $name : '?', $key);
                }
            }

            if (count($valid) > $perTask) {
                $dropped[] = sprintf('%s: more than %d skills, kept the first %d', $key, $perTask, $perTask);
                $valid = array_slice($valid, 0, $perTask);
            }

            if ($valid !== []) {
                $selection[(string) $key] = $valid;
            }
        }

        ksort($selection);

        return [$selection, $dropped];
    }

    public static function read(string $path): ?self
    {
        $data = Json::readFile($path);

        if ($data === []) {
            return null;
        }

        $tasks = [];

        foreach (Read::array($data, 'tasks') as $key => $names) {
            $tasks[(string) $key] = is_array($names) ? array_values(array_map('strval', $names)) : [];
        }

        return new self($tasks, Read::string($data, 'inventory_hash'), Read::string($data, 'tasks_hash'), Read::string($data, 'source', 'none'));
    }

    public function write(string $path): void
    {
        Json::writeFile($path, [
            'source' => $this->source,
            'inventory_hash' => $this->inventoryHash,
            'tasks_hash' => $this->tasksHash,
            'tasks' => $this->tasks,
        ]);
    }

    /** @param  array<string, list<string>>  $tasks */
    public function with(array $tasks): self
    {
        return new self([...$this->tasks, ...$tasks], $this->inventoryHash, $this->tasksHash, $this->source === 'none' ? 'agent' : $this->source);
    }

    public function count(): int
    {
        return count(Arr::where($this->tasks, fn (array $names): bool => $names !== []));
    }
}
