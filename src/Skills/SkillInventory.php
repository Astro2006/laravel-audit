<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Skills;

use Illuminate\Support\Arr;

final readonly class SkillInventory
{
    public const array PROJECT_DIRECTORIES = ['.claude/skills', '.agents/skills', '.opencode/skills'];

    public const string OWN_SKILL_PREFIX = 'laravel-audit';

    /** @param  array<string, Skill>  $skills  name => skill */
    public function __construct(public array $skills = []) {}

    public static function scan(string $basePath): self
    {
        $directories = array_map(fn (string $directory): string => rtrim($basePath, '/').'/'.$directory, self::PROJECT_DIRECTORIES);
        $skills = [];

        foreach ($directories as $directory) {
            foreach (glob($directory.'/*/SKILL.md') ?: [] as $file) {
                $skill = Skill::fromFile($file);

                if ($skill !== null && ! str_starts_with($skill->name, self::OWN_SKILL_PREFIX) && ! array_key_exists($skill->name, $skills)) {
                    $skills[$skill->name] = $skill;
                }
            }
        }

        ksort($skills);

        return new self($skills);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->skills);
    }

    public function get(string $name): ?Skill
    {
        return Arr::get($this->skills, $name);
    }

    public function isEmpty(): bool
    {
        return $this->skills === [];
    }

    public function hash(): string
    {
        $parts = array_map(fn (Skill $skill): string => $skill->name."\0".$skill->description."\0".filesize($skill->path), $this->skills);

        return substr(sha1(implode("\n", $parts)), 0, 12);
    }
}
