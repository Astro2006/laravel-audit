<?php

use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Facades\Process;

function herdHome(): string
{
    $home = tempDir('herd');
    $dir = $home.'/Library/Application Support/Herd/config/php/'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION.'/debug';
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/debug.ini', "zend_extension=xdebug.so\n");

    return $home;
}

it('finds Herd coverage when its ini loads Xdebug', function () {
    $home = herdHome();
    putenv('HOME='.$home);
    Process::fake(['*' => Process::result('on')]);

    $ini = (new ProjectInspector(fixture('project')))->herdCoverageIni();

    expect($ini)->toBe($home.'/Library/Application Support/Herd/config/php/'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION.'/debug/debug.ini');
    Process::assertRan(fn ($process) => $process->environment === ['PHPRC' => $ini, 'XDEBUG_MODE' => 'coverage']);
});

it('ignores Herd when its ini does not load Xdebug', function () {
    putenv('HOME='.herdHome());
    Process::fake(['*' => Process::result('off')]);

    expect((new ProjectInspector(fixture('project')))->herdCoverageIni())->toBeNull();
});

it('does not probe PHP without a Herd ini', function () {
    putenv('HOME='.tempDir('home'));
    Process::fake();

    expect((new ProjectInspector(fixture('project')))->herdCoverageIni())->toBeNull();
    Process::assertNothingRan();
});
