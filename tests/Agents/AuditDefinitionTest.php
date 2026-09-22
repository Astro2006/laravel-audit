<?php

use Astro2006\LaravelAudit\Agents\BackendAgent;
use Astro2006\LaravelAudit\Audits\AuditDefinition;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;

function publishedChecks(string $code): string
{
    $dir = resource_path(AuditDefinition::PUBLISHED_PATH);
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/backend.php', $code);

    return $dir.'/backend.php';
}

afterEach(function () {
    @unlink(resource_path(AuditDefinition::PUBLISHED_PATH.'/backend.php'));
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

    $agent = new BackendAgent;

    expect(array_map(fn ($task) => $task->name, $agent->tasks()))->toBe(['injection', 'summary'])
        ->and(array_keys($agent->checks()))->toBe(['sql-injection', 'custom', 'other'])
        ->and(AuditDefinition::outdatedPublished()['backend'])->toContain('env-outside-config', 'migration-safety')
        ->and(AuditDefinition::outdatedPublished())->not->toHaveKey('frontend');
});

it('rejects unknown snapshots', function () {
    AuditDefinition::fromFile(AuditType::Backend, publishedChecks("<?php return ['tasks' => ['x' => ['checks' => [], 'snapshots' => ['nope']]]];"));
})->throws(RuntimeException::class, 'Unknown snapshot "nope"');
