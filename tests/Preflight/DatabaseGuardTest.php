<?php

use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Support\ChildEnvironment;
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

it('lets exported variables win over phpunit env without force', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => phpunitWithEnv(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'shop_test'])]);

    $guard = new DatabaseGuard($project, $connections, 'mysql', inherited: ['DB_DATABASE' => 'shop']);

    expect($guard->check()->safe)->toBeFalse();
});

it('lets forced phpunit env win over exported variables', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => '<phpunit><php><env name="DB_CONNECTION" value="mysql"/><env name="DB_DATABASE" value="shop_test" force="true"/></php></phpunit>']);

    $guard = new DatabaseGuard($project, $connections, 'mysql', inherited: ['DB_DATABASE' => 'shop']);

    expect($guard->check())->safe->toBeTrue()->testDatabase->toBe('mysql shop_test@127.0.0.1');
});

it('removes everything from .env for child processes but keeps exported variables', function () {
    $project = guardProject(['.env' => "APP_ENV=local\nDB_DATABASE=shop\n"]);
    $environment = ChildEnvironment::for($project);

    putenv('AUDIT_EXPORTED=1');

    expect($environment->overrides())->toBe(['APP_ENV' => false, 'DB_DATABASE' => false, ...array_fill_keys(ChildEnvironment::AGENT_VARIABLES, false)])
        ->and($environment->inherited())->toHaveKey('AUDIT_EXPORTED')
        ->and(ChildEnvironment::for(guardProject([]))->overrides())->toBe(array_fill_keys(ChildEnvironment::AGENT_VARIABLES, false));

    putenv('AUDIT_EXPORTED');
});
