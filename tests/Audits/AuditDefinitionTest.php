<?php

use Astro2006\LaravelAudit\Audits\AuditDefinition;
use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Enums\Stage;

function publishedChecks(string $code): string
{
    $dir = resource_path(AuditDefinition::PUBLISHED_PATH.'/backend');
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/audit.php', $code);

    return $dir.'/audit.php';
}

afterEach(function () {
    @unlink(resource_path(AuditDefinition::PUBLISHED_PATH.'/backend/audit.php'));
});

it('reads tasks and checks from the audit file', function () {
    $definition = AuditDefinition::for(AuditType::Backend);
    $laravel = collect($definition->tasks)->firstWhere('name', 'laravel');

    expect($laravel->checks)->toBe(['env-outside-config', 'inline-validation'])
        ->and($laravel->dependsOn)->toBe(['conventions'])
        ->and($laravel->snapshots)->toBe([SnapshotType::CodeCandidates])
        ->and($definition->checks)->toHaveKey('migration-safety');
});

it('lets a published copy win and names the checks it lacks', function () {
    publishedChecks(<<<'PHP'
        <?php return ['sections' => [], 'tasks' => [
            'injection' => ['title' => 'Only injection', 'checks' => ['sql-injection' => 'Raw SQL with input.']],
        ]];
        PHP);

    $agent = new BackendAudit;

    expect(array_map(fn ($task) => $task->name, $agent->tasks()))->toBe(['injection', 'summary'])
        ->and(array_keys($agent->checks()))->toBe(['sql-injection', 'custom', 'other'])
        ->and(AuditDefinition::outdatedPublished()['backend'])->toContain('env-outside-config', 'migration-safety')
        ->and(AuditDefinition::outdatedPublished())->not->toHaveKey('frontend');
});

it('rejects unknown snapshots', function () {
    AuditDefinition::fromFile(AuditType::Backend, publishedChecks("<?php return ['tasks' => ['x' => ['checks' => [], 'snapshots' => ['nope']]]];"));
})->throws(RuntimeException::class, 'Unknown snapshot "nope"');

it('reads the stage of a task and defaults to standards', function () {
    $byName = collect(AuditDefinition::for(AuditType::Backend)->tasks)->keyBy('name');
    $published = AuditDefinition::fromFile(AuditType::Backend, publishedChecks("<?php return ['tasks' => ['x' => ['checks' => []]]];"));

    expect($byName['conventions']->stage)->toBe(Stage::Analyse)
        ->and($byName['injection']->stage)->toBe(Stage::Security)
        ->and($byName['jobs']->stage)->toBe(Stage::Standards)
        ->and($published->tasks[0]->stage)->toBe(Stage::Standards);
});

it('rejects unknown stages', function () {
    AuditDefinition::fromFile(AuditType::Backend, publishedChecks("<?php return ['tasks' => ['x' => ['checks' => [], 'stage' => 'later']]];"));
})->throws(RuntimeException::class, 'Unknown stage "later"');

it('splits the shipped tasks into ten analyse, fourteen standards and ten security tasks', function () {
    $counts = ['analyse' => 0, 'standards' => 0, 'security' => 0];

    foreach (AuditType::cases() as $type) {
        foreach (AuditDefinition::for($type)->tasks as $task) {
            $counts[$task->stage->value]++;
        }
    }

    expect($counts)->toBe(['analyse' => 10, 'standards' => 14, 'security' => 10]);
});

it('reads a turn cap per task', function () {
    $byName = collect(AuditDefinition::for(AuditType::Backend)->tasks)->keyBy('name');

    expect($byName['dead-code']->maxTurns)->toBe(30)
        ->and($byName['database']->maxTurns)->toBe(25)
        ->and($byName['laravel']->maxTurns)->toBeNull()
        ->and(AuditDefinition::turnCaps())->toBe([25, 30]);
});
