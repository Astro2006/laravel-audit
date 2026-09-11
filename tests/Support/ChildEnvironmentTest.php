<?php

use Astro2006\LaravelAudit\Support\ChildEnvironment;
use Astro2006\LaravelAudit\Support\ProjectInspector;

it('removes .env variables and agent markers from child processes', function () {
    $dir = tempDir('env');
    file_put_contents($dir.'/.env', "APP_KEY=base64:secret\nDB_PASSWORD=secret\n");

    expect(ChildEnvironment::for(new ProjectInspector($dir))->overrides())
        ->toMatchArray(['APP_KEY' => false, 'DB_PASSWORD' => false, 'CLAUDECODE' => false, 'AI_AGENT' => false, 'OPENCODE' => false, 'CODEX_THREAD_ID' => false]);
});
