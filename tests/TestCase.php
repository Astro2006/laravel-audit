<?php

namespace Astro2006\LaravelAudit\Tests;

use Astro2006\LaravelAudit\LaravelAuditServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // audit:install publishes config/audit.php into the Testbench skeleton
        // app. A copy left there would shadow the package config in every
        // later test, so each test starts without it.
        $published = static::applicationBasePath().'/config/audit.php';

        if (is_file($published)) {
            unlink($published);
        }

        // Herd (coverage) and Playwright are looked up below HOME. An empty
        // one keeps the tests independent of what this machine has installed.
        $home = dirname(__DIR__).'/build/tests/home';

        if (! is_dir($home)) {
            mkdir($home, 0777, true);
        }

        putenv('HOME='.$home);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('HOME='.$_SERVER['HOME']);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelAuditServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
