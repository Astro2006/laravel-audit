<?php

use Astro2006\LaravelAudit\Console\Output\InstallConsole;
use Astro2006\LaravelAudit\Install\BoostHandoff;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Skills\Skill;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function handoff(ProjectInspector $project, bool $yes = false, bool $dryRun = false): string
{
    $output = new BufferedOutput;
    $console = new InstallConsole(new OutputStyle(new ArrayInput([]), $output), Messages::fromConfig(), false);

    (new BoostHandoff($project, new PlanExecutor($project), 'php'))->handle($console, $yes, $dryRun, false);

    return $output->fetch();
}

function boostProject(?array $boostJson): ProjectInspector
{
    return installProject(['laravel/boost' => 'v2.9.1'], $boostJson === null ? [] : ['boost.json' => json_encode($boostJson)]);
}

it('skips the hand-off without Boost', function () {
    Process::fake();

    expect(handoff(installProject()))->toContain('Laravel Boost is not installed');

    Process::assertNothingRan();
});

it('asks to set Boost up first when boost.json has no agents', function () {
    Process::fake();

    expect(handoff(boostProject(null)))->toContain('php artisan boost:install');

    Process::assertNothingRan();
});

it('registers the package in boost.json and refreshes Boost with --yes', function () {
    Process::fake();
    $project = boostProject(['agents' => ['claude_code', 'opencode'], 'packages' => ['acme/other'], 'skills' => []]);

    $output = handoff($project, yes: true);

    expect(json_decode(file_get_contents($project->path('boost.json')), true))
        ->toBe(['agents' => ['claude_code', 'opencode'], 'packages' => ['acme/other', 'astro2006/laravel-audit'], 'skills' => []])
        ->and($output)->toContain('php artisan boost:update --no-discover');

    Process::assertRan(fn ($process) => $process->command === ['php', 'artisan', 'boost:update', '--no-discover']);
});

it('only names the command without interaction and without --yes', function () {
    Process::fake();

    expect(handoff(boostProject(['agents' => ['claude_code']])))->toContain('php artisan boost:update --discover');

    Process::assertNothingRan();
});

it('shows what it would run in a dry run', function () {
    Process::fake();

    expect(handoff(boostProject(['agents' => ['claude_code'], 'packages' => ['astro2006/laravel-audit']]), dryRun: true))
        ->toContain('Boost: would run php artisan boost:update --no-discover');

    Process::assertNothingRan();
});

it('ships a short guideline and a skill in the layout Boost discovers', function () {
    $root = dirname(__DIR__, 2).'/resources/boost';
    $skill = Skill::fromFile($root.'/skills/laravel-audit/SKILL.md');

    expect(count(file($root.'/guidelines/core.blade.php')))->toBeLessThanOrEqual(10)
        ->and($skill?->name)->toBe('laravel-audit')
        ->and(mb_strlen((string) $skill?->description))->toBeLessThanOrEqual(1024)
        ->and($skill?->description)->not->toContain(': ');
});
