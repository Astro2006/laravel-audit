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
        'phpunit.xml' => phpunitWithEnv(['APP_ENV' => 'testing']),
        '.env' => "DB_CONNECTION=mysql\nDB_DATABASE=shop\n",
        '.env.testing' => "DB_CONNECTION=mysql\nDB_DATABASE=shop_testing\n",
    ]);

    $result = (new DatabaseGuard($project, $connections, 'mysql'))->check();

    expect($result->safe)->toBeTrue()
        ->and($result->testDatabase)->toBe('mysql shop_testing@127.0.0.1');
});

it('lets phpunit env win over .env.testing', function () use ($connections) {
    $project = guardProject([
        'phpunit.xml' => phpunitWithEnv(['APP_ENV' => 'testing', 'DB_DATABASE' => 'shop']),
        '.env.testing' => "DB_CONNECTION=mysql\nDB_DATABASE=shop_testing\nDB_HOST=127.0.0.1\n",
    ]);

    expect((new DatabaseGuard($project, $connections, 'mysql'))->check()->safe)->toBeFalse();
});

it('reads .env, not .env.testing, when nothing sets APP_ENV to testing', function () use ($connections) {
    $project = guardProject([
        'phpunit.xml' => '<phpunit/>',
        '.env' => "DB_CONNECTION=mysql\nDB_DATABASE=shop\n",
        '.env.testing' => "DB_CONNECTION=mysql\nDB_DATABASE=shop_testing\n",
    ]);

    expect((new DatabaseGuard($project, $connections, 'mysql'))->check()->safe)->toBeFalse();
});

it('reads .env.<APP_ENV> for the environment the shell exports', function () use ($connections) {
    $project = guardProject([
        'phpunit.xml' => phpunitWithEnv(['APP_ENV' => 'testing']),
        '.env' => "DB_CONNECTION=mysql\nDB_DATABASE=shop\n",
        '.env.testing' => "DB_DATABASE=shop_testing\n",
        '.env.local' => "DB_DATABASE=shop\n",
    ]);

    expect((new DatabaseGuard($project, $connections, 'mysql', inherited: ['APP_ENV' => 'local']))->check()->safe)->toBeFalse()
        ->and((new DatabaseGuard($project, $connections, 'mysql'))->check()->safe)->toBeTrue();
});

it('reads the connection from DB_URL', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => phpunitWithEnv(['DB_URL' => 'mysql://root@127.0.0.1:3306/shop'])]);

    expect((new DatabaseGuard($project, $connections, 'mysql'))->check())->safe->toBeFalse()->testDatabase->toBe('mysql shop@127.0.0.1');
});

it('tells databases on different ports apart', function () use ($connections) {
    $project = guardProject(['phpunit.xml' => phpunitWithEnv(['DB_DATABASE' => 'shop', 'DB_PORT' => '3307'])]);
    $withPort = ['mysql' => [...$connections['mysql'], 'port' => 3306]];

    expect((new DatabaseGuard($project, $withPort, 'mysql'))->check()->safe)->toBeTrue()
        ->and((new DatabaseGuard($project, $connections, 'mysql'))->check()->safe)->toBeFalse();
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

it('keeps a variable the shell exported with another value than .env', function () {
    $environment = ChildEnvironment::for(guardProject(['.env' => "DB_DATABASE=shop\nAPP_ENV=local\n"]));

    putenv('DB_DATABASE=ci');
    putenv('APP_ENV=local');

    expect($environment->toolOverrides())->not->toHaveKey('DB_DATABASE')->toHaveKey('APP_ENV')
        ->and($environment->inherited())->toMatchArray(['DB_DATABASE' => 'ci'])->not->toHaveKey('APP_ENV')
        ->and($environment->overrides())->toHaveKeys(['DB_DATABASE', 'APP_ENV']);

    putenv('DB_DATABASE');
    putenv('APP_ENV');
});
