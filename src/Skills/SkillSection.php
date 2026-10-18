<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Skills;

final class SkillSection
{
    /**
     * The skills section of every task prompt that has skills.
     *
     * @return array<string, string> task key => markdown
     */
    public static function render(SkillSelection $selection, SkillInventory $inventory, int $maxChars): array
    {
        $sections = [];

        foreach ($selection->tasks as $key => $names) {
            $parts = [];

            foreach ($names as $name) {
                $skill = $inventory->get($name);

                if ($skill !== null) {
                    $parts[] = "### Skill `{$skill->name}`\n\n".$skill->body($maxChars);
                }
            }

            if ($parts !== []) {
                $sections[$key] = "## Skills\n\nApply these skills of the project: they are its conventions, and code that follows them is not a finding. Do not look for other skills.\n\n".implode("\n\n", $parts);
            }
        }

        return $sections;
    }
}
