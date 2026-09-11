<?php

use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function guardProject(array $files): ProjectInspector
{
    $dir = tempDir('guard');

    foreach ($files as $name => $contents) {
        file_put_contents($dir.'/'.$name, $contents);
    }

    return new ProjectInspector($dir);
}

function phpunitWithEnv(array $env): string
{
    $lines = implode('', array_map(fn ($k, $v) => "<env name=\"{$k}\" value=\"{$v}\"/>", array_keys($env), $env));

    return "<phpunit><php>{$lines}</php></phpunit>";
}

$connections = [
    'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'shop'],
    'sqlite' => ['driver' => 'sqlite', 'database' => '/tmp/app.sqlite'],
];

it('accepts an in-memory sqlite test database', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => phpunitWithEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'])]);

    $result = (new DatabaseGuard($project, $connections, 'mysql'))->check();

    expect($result->safe)->toBeTrue()
        ->and($result->testDatabase)->toBe('sqlite :memory:')
        ->and($result->appDatabase)->toBe('mysql shop@127.0.0.1');
});

it('refuses when the tests would use the app database', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => '<phpunit/>', '.env' => "DB_CONNECTION=mysql\nDB_DATABASE=shop\nDB_HOST=localhost\n"]);

    expect((new DatabaseGuard($project, $connections, 'mysql'))->check()->safe)->toBeFalse();
});

it('accepts a separate database from .env.testing', function () use ($connections) {
    $project = guardProject([
        'phpunit.xml' => '<phpunit/>',
        '.env' => "DB_CONNECTION=mysql\nDB_DATABASE=shop\n",
        '.env.testing' => "DB_CONNECTION=mysql\nDB_DATABASE=shop_testing\n",
    ]);

    $result = (new DatabaseGuard($project, $connections, 'mysql'))->check();

    expect($result->safe)->toBeTrue()
        ->and($result->testDatabase)->toBe('mysql shop_testing@127.0.0.1');
});

it('lets phpunit env win over .env.testing', function () use ($connections) {
    $project = guardProject([
        'phpunit.xml' => phpunitWithEnv(['DB_DATABASE' => 'shop']),
        '.env.testing' => "DB_CONNECTION=mysql\nDB_DATABASE=shop_testing\nDB_HOST=127.0.0.1\n",
    ]);

    expect((new DatabaseGuard($project, $connections, 'mysql'))->check()->safe)->toBeFalse();
});

it('compares sqlite files by path', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => phpunitWithEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/tmp/app.sqlite'])]);

    expect((new DatabaseGuard($project, $connections, 'sqlite'))->check()->safe)->toBeFalse();
});
