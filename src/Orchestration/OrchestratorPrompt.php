<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Skills\Skill;

final class OrchestratorPrompt
{
    public const string SKILL_FILE = 'resources/boost/skills/laravel-audit/SKILL.md';

    private const string HEADER = <<<'TEXT'
        # Laravel Audit — orchestrate run `%1$s`

        You are the orchestrator of this audit run. The run is already open: its ID is `%1$s`, and every `audit:tool` command needs `--run=%1$s`. Nobody answers questions in this session: when `gate` is `aborted`, there is no user to ask, so stop and report the failing tests. Begin with `php artisan audit:tool catalog --run=%1$s` and follow the stages below. When you are done, answer with a short summary for the user: the findings to fix first (by ID and title), the tasks that failed or were left out and why, and the cost (`spend_usd`).

        TEXT;

    /** @return string the skill text as the prompt of a headless session, with the run ID filled in */
    public static function render(string $runId): string
    {
        $skill = Skill::fromFile(dirname(__DIR__, 2).'/'.self::SKILL_FILE);

        return sprintf(self::HEADER, $runId).($skill?->body(PHP_INT_MAX) ?? '');
    }
}
