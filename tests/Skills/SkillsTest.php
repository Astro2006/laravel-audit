<?php

use Astro2006\LaravelAudit\Agents\Drivers\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\Phases\SelectSkills;
use Astro2006\LaravelAudit\Skills\Skill;
use Astro2006\LaravelAudit\Skills\SkillInventory;
use Astro2006\LaravelAudit\Skills\SkillSection;
use Astro2006\LaravelAudit\Skills\SkillSelection;
use Astro2006\LaravelAudit\Skills\SkillSelector;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Facades\Process;

function skillProject(array $skills): string
{
    $dir = tempDir('skills');

    foreach ($skills as $path => $contents) {
        @mkdir(dirname($dir.'/'.$path), 0777, true);
        file_put_contents($dir.'/'.$path, $contents);
    }

    return $dir;
}

function skillFile(string $name, string $description, string $body = 'Rules.'): string
{
    return "---\nname: {$name}\ndescription: {$description}\n---\n\n{$body}\n";
}

it('reads name, description and body of a skill, also folded descriptions', function () {
    $dir = skillProject([
        '.claude/skills/models/SKILL.md' => skillFile('models', 'Eloquent model conventions.', "# Models\n\nAlways cast dates."),
        '.claude/skills/folded/SKILL.md' => "---\nname: folded\ndescription: >\n  Spans\n  two lines.\n---\nBody\n",
        '.claude/skills/broken/SKILL.md' => "no frontmatter\n",
    ]);

    $models = Skill::fromFile($dir.'/.claude/skills/models/SKILL.md');

    expect($models)->name->toBe('models')->description->toBe('Eloquent model conventions.')
        ->and($models->body(1000))->toBe("# Models\n\nAlways cast dates.")
        ->and($models->body(8))->toStartWith('# Models')->toContain('(Skill shortened to 8 characters.)')
        ->and(Skill::fromFile($dir.'/.claude/skills/folded/SKILL.md')?->description)->toBe('Spans two lines.')
        ->and(Skill::fromFile($dir.'/.claude/skills/broken/SKILL.md'))->toBeNull();
});

it('shortens a description to its first sentence for the selection prompt', function () {
    $skill = new Skill('ai', "TRIGGER when working with the AI SDK. Activate when building agents,\n chatbots and more.", '/x');

    expect($skill->summary(100))->toBe('TRIGGER when working with the AI SDK.')
        ->and($skill->summary(20))->toBe('TRIGGER when workin…')
        ->and((new Skill('x', 'No full stop here', '/x'))->summary(100))->toBe('No full stop here');
});

it('finds the skills Boost installed for Claude Code and OpenCode, but not its own', function () {
    $dir = skillProject([
        '.claude/skills/models/SKILL.md' => skillFile('models', 'From Claude.'),
        '.agents/skills/models/SKILL.md' => skillFile('models', 'From OpenCode.'),
        '.agents/skills/phpstan/SKILL.md' => skillFile('phpstan', 'Static analysis.'),
        '.claude/skills/laravel-audit/SKILL.md' => skillFile('laravel-audit', 'Run the audit.'),
    ]);

    $inventory = SkillInventory::scan($dir);

    expect(array_keys($inventory->skills))->toBe(['models', 'phpstan'])
        ->and($inventory->get('models')?->description)->toBe('From Claude.')
        ->and(SkillInventory::scan(tempDir('none'))->isEmpty())->toBeTrue();
});

it('keeps only known tasks and skills, and at most the limit per task', function () {
    $inventory = SkillInventory::scan(skillProject([
        '.claude/skills/models/SKILL.md' => skillFile('models', 'Models.'),
        '.claude/skills/php/SKILL.md' => skillFile('php', 'PHP.'),
        '.claude/skills/enums/SKILL.md' => skillFile('enums', 'Enums.'),
    ]));

    [$selection, $dropped] = SkillSelection::validate(
        ['backend.data' => ['models', 'php', 'enums'], 'backend.nope' => ['php'], 'backend.jobs' => ['made-up'], 'backend.logic' => 'php'],
        ['backend.data', 'backend.jobs', 'backend.logic'],
        $inventory,
        2,
    );

    expect($selection)->toBe(['backend.data' => ['models', 'php'], 'backend.logic' => ['php']])
        ->and($dropped)->toBe(['backend.data: more than 2 skills, kept the first 2', 'unknown task "backend.nope"', 'unknown skill "made-up" for backend.jobs']);
});

it('inlines the selected skills into the task prompt and its hash', function () {
    $dir = skillProject(['.claude/skills/models/SKILL.md' => skillFile('models', 'Models.', 'Casts live in casts().')]);
    $skills = SkillSection::render(new SkillSelection(['backend.data' => ['models']]), SkillInventory::scan($dir), 2000);
    $task = collect((new BackendAudit)->tasks())->firstWhere('name', 'data');

    $with = (new BackendAudit)->renderTask($task, promptContext(['skills' => $skills]));
    $without = (new BackendAudit)->renderTask($task, promptContext());

    expect($with->text)->toContain("## Skills\n")->toContain("### Skill `models`\n\nCasts live in casts().")
        ->and($without->text)->not->toContain('## Skills')
        ->and($with->hash)->not->toBe($without->hash);
});

function selector(array $config = []): SkillSelector
{
    return new SkillSelector(auditConfig($config));
}

it('asks the agent once and validates its answer', function () {
    $dir = skillProject(['.claude/skills/models/SKILL.md' => skillFile('models', 'Eloquent models.')]);
    Process::fake(['*' => Process::result(claudeJson(['result' => "Sure:\n{\"backend.data\": [\"models\"], \"backend.jobs\": [\"queues\"]}"]))]);
    $run = AuditRun::create($dir.'/storage/audit');

    [$selection, $warnings] = selector()->select(SelectSkills::tasks([AuditType::Backend]), SkillInventory::scan($dir), new ClaudeCodeDriver, $run, new ProjectInspector($dir));

    expect($selection)->tasks->toBe(['backend.data' => ['models']])->source->toBe('agent')
        ->and($warnings)->toBe(['Skill selection: dropped unknown skill "queues" for backend.jobs.'])
        ->and(file_get_contents($run->logsDir().'/skills.prompt.md'))->toContain('- models: Eloquent models.')->toContain('- backend.data (Models and data exposure): ');

    Process::assertRan(fn ($process) => $process->environment['AUDIT_TASK'] === 'skills');
});

it('reuses the selection of an earlier run while skills and tasks are the same', function () {
    $dir = skillProject(['.claude/skills/models/SKILL.md' => skillFile('models', 'Eloquent models.')]);
    Process::fake(['*' => Process::result(claudeJson(['result' => '{"backend.data": ["models"]}']))]);
    $tasks = SelectSkills::tasks([AuditType::Backend]);
    $inventory = SkillInventory::scan($dir);

    $first = AuditRun::create($dir.'/storage/audit', new DateTimeImmutable('2026-09-23 10:00:00'));
    [$selection] = selector()->select($tasks, $inventory, new ClaudeCodeDriver, $first, new ProjectInspector($dir));
    $selection->write($first->dir().'/'.SkillSelection::FILE);

    $second = AuditRun::create($dir.'/storage/audit', new DateTimeImmutable('2026-09-23 11:00:00'));
    [$cached] = selector()->select($tasks, $inventory, new ClaudeCodeDriver, $second, new ProjectInspector($dir));
    [$fresh] = selector()->select($tasks, $inventory, new ClaudeCodeDriver, $second, new ProjectInspector($dir), reselect: true);

    expect($cached)->source->toBe('cache')->tasks->toBe(['backend.data' => ['models']])
        ->and($fresh->source)->toBe('agent');

    Process::assertRanTimes(fn () => true, 2);
});

it('takes the selection from the config or skips it', function () {
    $dir = skillProject(['.claude/skills/models/SKILL.md' => skillFile('models', 'Eloquent models.')]);
    Process::fake();
    $run = AuditRun::create($dir.'/storage/audit');
    $tasks = SelectSkills::tasks([AuditType::Backend]);
    $inventory = SkillInventory::scan($dir);

    [$configured] = selector(['agent' => ['skills' => ['backend.data' => ['models']]]])->select($tasks, $inventory, new ClaudeCodeDriver, $run, new ProjectInspector($dir));
    [$none] = selector(['agent' => ['skills' => 'none']])->select($tasks, $inventory, new ClaudeCodeDriver, $run, new ProjectInspector($dir));

    expect($configured)->source->toBe('config')->tasks->toBe(['backend.data' => ['models']])
        ->and($none->tasks)->toBe([]);

    Process::assertNothingRan();
});

it('runs without skills when the answer is no JSON', function () {
    $dir = skillProject(['.claude/skills/models/SKILL.md' => skillFile('models', 'Eloquent models.')]);
    Process::fake(['*' => Process::result(claudeJson(['result' => 'I would pick models.']))]);

    [$selection, $warnings] = selector()->select(SelectSkills::tasks([AuditType::Backend]), SkillInventory::scan($dir), new ClaudeCodeDriver, AuditRun::create($dir.'/storage/audit'), new ProjectInspector($dir));

    expect($selection->tasks)->toBe([])
        ->and($warnings)->toBe(['The skill selection answer was no JSON object; the tasks run without skills.']);
});
