<?php

use Astro2006\LaravelAudit\Orchestration\WorkerLock;

it('lets only one worker hold the lock', function () {
    $lock = new WorkerLock(tempDir('lock').'/worker.lock');

    expect($lock->acquire(WorkerLock::TASKS, ['backend.injection']))->toBeTrue()
        ->and($lock->acquire(WorkerLock::PREFLIGHT, ['tests']))->toBeFalse()
        ->and($lock->current())->toMatchArray(['kind' => 'tasks', 'items' => ['backend.injection'], 'pid' => null]);
});

it('records the pid of the worker that claims the lock and frees it on release', function () {
    $lock = new WorkerLock(tempDir('lock').'/worker.lock');
    $lock->acquire(WorkerLock::TASKS, ['backend.injection']);

    $lock->claim(getmypid());

    expect($lock->current())->pid->toBe(getmypid());

    $lock->release();

    expect($lock->current())->toBeNull()
        ->and($lock->acquire(WorkerLock::PREFLIGHT, ['tests']))->toBeTrue();
});

it('drops a lock no worker claimed within the grace period', function () {
    $path = tempDir('lock').'/worker.lock';
    file_put_contents($path, json_encode(['kind' => 'tasks', 'items' => ['backend.injection'], 'pid' => null, 'started_at' => time() - 60]));
    $lock = new WorkerLock($path);

    expect($lock->current())->toBeNull()
        ->and($path)->not->toBeFile();
});

it('keeps a fresh lock that no worker claimed yet', function () {
    $path = tempDir('lock').'/worker.lock';
    file_put_contents($path, json_encode(['kind' => 'tasks', 'items' => ['backend.injection'], 'pid' => null, 'started_at' => time() - 5]));

    expect((new WorkerLock($path))->current())->not->toBeNull();
});

it('drops a lock whose worker process is gone', function () {
    $path = tempDir('lock').'/worker.lock';
    file_put_contents($path, json_encode(['kind' => 'tasks', 'items' => ['backend.injection'], 'pid' => 999_999, 'started_at' => time()]));

    expect((new WorkerLock($path))->current())->toBeNull();
})->skip(! function_exists('posix_kill'), 'needs posix_kill');

it('refuses the lock when another process created the file first', function () {
    $path = tempDir('lock').'/worker.lock';
    $lock = new WorkerLock($path);
    $other = new WorkerLock($path);

    expect($lock->acquire(WorkerLock::TASKS, ['a']))->toBeTrue();

    file_put_contents($path, json_encode(['kind' => 'tasks', 'items' => ['a'], 'pid' => getmypid(), 'started_at' => time()]));

    expect($other->acquire(WorkerLock::TASKS, ['b']))->toBeFalse()
        ->and($lock->current())->items->toBe(['a']);
});
