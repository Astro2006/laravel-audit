<?php

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Console\TaskBoard;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Support\Messages;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;

function boardJob(string $key, AuditType $audit = AuditType::Backend): AgentJob
{
    return new AgentJob($key, $audit, new ReportSpec($key, 'BE', ['other' => 'x'], ['Findings']), new AgentRequest($key, 'Task', fixture('project')), 'h');
}

function boardOutcome(AgentJob $job, AuditStatus $status): TaskOutcome
{
    return new TaskOutcome($job->key, $job->audit, $status, null, null, [], [], [], new Telemetry, false, 'h', null);
}

/** @param list<string> $lines */
function plainLines(array $lines): array
{
    return array_map(fn (string $line): string => Helper::removeDecoration(new OutputFormatter, $line), $lines);
}

it('lists every task of the run per audit up front', function () {
    $board = new TaskBoard([boardJob('backend.injection'), boardJob('backend.summary'), boardJob('tests.gaps', AuditType::Tests)], new Messages('en'), 0.0);

    expect(plainLines($board->plan()))->toBe([
        '  backend · 2 tasks: injection, summary',
        '  tests · 1 tasks: gaps',
    ]);
});

it('shows what runs, for how long, what waits and what is done', function () {
    $jobs = [boardJob('backend.a'), boardJob('backend.b'), boardJob('backend.c'), boardJob('backend.d')];
    $board = new TaskBoard($jobs, new Messages('en'), 100.0);

    $board->started($jobs[0], 100.0);
    $board->finished(boardOutcome($jobs[0], AuditStatus::Failed));
    $board->started($jobs[1], 130.0);
    $board->repairing($jobs[1]);

    $lines = plainLines($board->live(195.0, 80));

    expect($lines[0])->toBe('  ◷ 1 running · 2 waiting · 0 done · 1 failed (of 4) · 1m35s')
        ->and($lines[1])->toStartWith('  ● backend.b ...')->toEndWith(' 1m05s · repair pass')
        ->and(mb_strlen($lines[1]))->toBe(78)
        ->and($lines[2])->toBe('  ◌ waiting: backend.c, backend.d')
        ->and($lines)->toHaveCount(3);
});

it('is empty once every task is done', function () {
    $job = boardJob('backend.a');
    $board = new TaskBoard([$job], new Messages('en'), 0.0);
    $board->started($job, 0.0);
    $board->finished(boardOutcome($job, AuditStatus::Completed));

    expect($board->live(10.0, 80))->toBe([]);
});
