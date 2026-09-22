<?php

use Astro2006\LaravelAudit\Install\ComposerPreview;
use Astro2006\LaravelAudit\Install\InstallPlanner;
use Astro2006\LaravelAudit\Install\PlanExecutor;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Support\Facades\Process;

function installProject(array $lockDev = [], array $files = []): ProjectInspector
{
    $dir = tempDir('install');
    file_put_contents($dir.'/composer.json', json_encode(['require-dev' => []]));
    file_put_contents($dir.'/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework', 'version' => 'v13.2.0']],
        'packages-dev' => array_map(fn ($name, $version) => ['name' => $name, 'version' => $version], array_keys($lockDev), $lockDev),
    ]));

    foreach ($files as $name => $contents) {
        @mkdir(dirname($dir.'/'.$name), 0777, true);
        file_put_contents($dir.'/'.$name, $contents);
    }

    return new ProjectInspector($dir);
}

it('plans only the optional parts for a pest 5 project', function () {
    $project = installProject(['pestphp/pest' => 'v5.1.0', 'larastan/larastan' => 'v3.0.0', 'laravel/pint' => 'v1.20.0']);

    $plan = (new InstallPlanner($project, ['audits' => auditsConfig()]))->plan();

    expect($plan->present)->toBe(['larastan/larastan', 'laravel/pint', 'pestphp/pest'])
        ->and($plan->names())->toBe(['laravel/boost', 'pestphp/pest-plugin-browser', 'playwright'])
        ->and($plan->setup)->toContain('boost', 'phpstan-stub')
        ->and(array_map(fn ($note) => $note->key, $plan->notes))->toContain('install.no_browser_tests');
});

it('marks pest as an upgrade for phpunit-only projects and adds paratest', function () {
    $project = installProject(['phpunit/phpunit' => '12.0.0']);

    $plan = (new InstallPlanner($project, ['audits' => auditsConfig()]))->plan();
    $pest = collect($plan->packages)->firstWhere('name', 'pestphp/pest');

    expect($pest->isUpgrade())->toBeTrue()
        ->and($plan->names())->toContain('brianium/paratest')
        ->and(array_map(fn ($note) => $note->key, $plan->notes))->toContain('install.phpunit_only');
});

it('marks old pest versions as an upgrade', function () {
    $plan = (new InstallPlanner(installProject(['pestphp/pest' => 'v3.8.0']), ['audits' => auditsConfig()]))->plan();

    expect(collect($plan->packages)->firstWhere('name', 'pestphp/pest')->upgradeReason)->toBe('Pest 3 → Pest 4+');
});

it('drops browser packages when the pest upgrade is declined', function () {
    $plan = (new InstallPlanner(installProject(['phpunit/phpunit' => '12.0.0']), ['audits' => auditsConfig()]))->plan();

    $selected = $plan->only(['laravel/boost', 'brianium/paratest', 'pestphp/pest-plugin-browser', 'playwright']);

    expect($selected->names())->toBe(['laravel/boost', 'brianium/paratest'])
        ->and($selected->setup)->not->toContain('playwright-browsers');
});

it('skips browser packages when neither tests nor frontend is active', function () {
    $plan = (new InstallPlanner(installProject(['pestphp/pest' => 'v5.1.0']), ['audits' => auditsConfig(['backend', 'dependencies'])]))->plan();

    expect($plan->names())->toBe(['laravel/boost']);
});

it('bundles packages into one composer and one npm command', function () {
    $project = installProject(['phpunit/phpunit' => '12.0.0'], ['pnpm-lock.yaml' => '']);
    $plan = (new InstallPlanner($project, ['audits' => auditsConfig()]))->plan();
    $executor = new PlanExecutor($project);

    expect($executor->composerCommand($plan, dryRun: true))->toBe([
        'composer', 'require', '--dev', 'laravel/boost', 'brianium/paratest', 'pestphp/pest', 'pestphp/pest-plugin-browser',
        '--with-all-dependencies', '--dry-run', '--no-interaction',
    ])
        ->and($executor->npmCommand($plan))->toBe(['pnpm', 'add', '--save-dev', 'playwright@latest'])
        ->and($executor->setupCommand('playwright-browsers'))->toBe(['pnpm', 'exec', 'playwright', 'install']);
})->skip(fn () => (new ProjectInspector('/'))->findExecutable('composer') === null, 'composer not in PATH');

it('writes the phpstan stub but never overwrites a config', function () {
    $project = installProject();
    $executor = new PlanExecutor($project);

    expect($executor->writeStub(5))->toBeTrue()
        ->and(file_get_contents($project->path('phpstan.neon')))->toContain('level: 5')
        ->and($executor->writeStub(9))->toBeFalse()
        ->and(file_get_contents($project->path('phpstan.neon')))->toContain('level: 5');
});

it('lists composer side effects on packages nobody asked for', function () {
    $output = <<<'TXT'
        Loading composer repositories with package information
        Updating dependencies
        Lock file operations: 3 installs, 2 updates, 1 removal
          - Locking laravel/boost (v1.4.0)
          - Upgrading symfony/process (v7.3.0 => v7.3.4)
          - Upgrading laravel/boost (v1.0.0 => v1.4.0)
          - Downgrading psr/log (3.0.2 => 3.0.1)
          - Removing old/package (1.0.0)
          - Installing laravel/mcp (v0.2.0)
        TXT;

    expect((new ComposerPreview)->sideEffects($output, ['laravel/boost']))->toBe([
        'symfony/process v7.3.0 → v7.3.4',
        'psr/log 3.0.2 → 3.0.1',
        'old/package 1.0.0 → removed',
    ]);
});

it('shows the plan with --dry-run and changes nothing', function () {
    $dir = tempDir('install-cmd');
    (new PreflightReuse)->copy(fixture('project'), $dir);
    config()->set('audit.base_path', $dir);
    config()->set('audit.output_path', $dir.'/storage/audit');
    config()->set('audit.report_language', 'en');
    Process::fake();

    $this->artisan('audit:install', ['--dry-run' => true])
        ->expectsOutputToContain('I need these packages:')
        ->expectsOutputToContain('laravel/boost')
        ->expectsOutputToContain('Dry run — nothing was changed.')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

it('installs nothing without interaction unless --yes is given', function () {
    $dir = tempDir('install-cmd');
    (new PreflightReuse)->copy(fixture('project'), $dir);
    config()->set('audit.base_path', $dir);
    config()->set('audit.report_language', 'en');
    Process::fake();

    $this->artisan('audit:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Nothing installed (--no-interaction without --yes).')
        ->assertExitCode(1);

    Process::assertNothingRan();
});

it('installs the plan with --yes in one composer call', function () {
    $dir = tempDir('install-cmd');
    (new PreflightReuse)->copy(fixture('project'), $dir);
    config()->set('audit.base_path', $dir);
    config()->set('audit.output_path', $dir.'/storage/audit');
    config()->set('audit.report_language', 'de');
    Process::fake(['*' => Process::result('')]);

    $this->artisan('audit:install', ['--yes' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Fertig.')
        ->expectsOutputToContain('storage/audit/.gitignore angelegt')
        ->assertExitCode(0);

    Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'require --dev laravel/boost') && ! in_array('--dry-run', (array) $process->command, true), 1);
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'playwright@latest'));
    expect($dir.'/storage/audit/.gitignore')->toBeFile();
});
