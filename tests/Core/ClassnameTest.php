<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Str;

dataset('classnames', [
    ['Actions', 'Action'],
    ['Commands', 'Command'],
    ['DTOs', 'Dto'],
    ['Enums', 'Enum'],
    ['Events', 'Event'],
    ['Helpers', 'Helper'],
    ['Http\Controllers', 'Controller'],
    ['Interfaces', 'Interface'],
    ['Jobs', 'Job'],
    ['Listeners', 'Listener'],
    ['Livewire', ''],
    ['Middleware', 'Middleware'],
    ['Models', ''],
    ['Notifications', 'Notification'],
    ['Observers', 'Observer'],
    ['Policies', 'Policy'],
    ['Providers', 'Provider'],
    ['Requests', 'Request'],
    ['Rules', 'Rule'],
    ['Traits', 'Trait'],
    ['Views', 'View'],
]);

it('names a class under Services for the role it plays', function () {
    $root = dirname(__DIR__, 2);
    $roles = ['Service', 'Connector', 'Request', 'Data'];
    $offences = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src/Services')) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR);

        if (Str::contains($path, DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (! Str::endsWith($file->getBasename('.php'), $roles)) {
            $offences[] = $path;
        }
    }

    expect($offences)->toBe([]);
})->group('core');

it('has suffix', function ($namespace, $suffix) {
    expect("Astro2006\\LaravelAudit\\{$namespace}")
        ->classes()
        ->toHaveSuffix($suffix);
})->with('classnames')->group('core');

it('keeps artisan commands in Commands', function () {
    expect('Astro2006\LaravelAudit\Commands')
        ->classes()
        ->toExtend(Command::class);
})->group('core');

it('keeps only console output helpers in Console', function () {
    expect('Astro2006\LaravelAudit\Console')
        ->classes()
        ->not->toExtend(Command::class)
        ->not->toHaveSuffix('Command');
})->group('core');
