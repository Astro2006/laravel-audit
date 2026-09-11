<?php

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\Steps\AboutStep;
use Astro2006\LaravelAudit\Preflight\Steps\BrowserStep;
use Astro2006\LaravelAudit\Preflight\Steps\ComposerStep;
use Astro2006\LaravelAudit\Preflight\Steps\PhpstanStep;
use Astro2006\LaravelAudit\Preflight\Steps\PintStep;
use Astro2006\LaravelAudit\Preflight\Steps\RoutesStep;
use Astro2006\LaravelAudit\Preflight\Steps\TestsStep;
use Astro2006\LaravelAudit\Preflight\Steps\TranslationsStep;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

function stepContext(array $without = [], array $config = [], ?string $project = null): PreflightContext
{
    $dir = tempDir('steps');

    return new PreflightContext(
        new ProjectInspector($project ?? fixture('project')),
        $dir.'/preflight',
        $dir.'/logs',
        array_replace_recursive(require dirname(__DIR__, 2).'/config/audit.php', $config),
        RunSelection::resolve(['tests' => true, 'frontend' => true, 'backend' => true, 'dependencies' => true], [], $without),
    );
}

function safeGuard(ProjectInspector $project): DatabaseGuard
{
    return new DatabaseGuard($project, ['mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'shop'], 'sqlite' => ['driver' => 'sqlite']], 'mysql');
}

it('reads app info from artisan about', function () {
    Process::fake(['*about*' => Process::result(json_encode(['environment' => ['application_name' => 'Shop', 'laravel_version' => '13.2.0', 'php_version' => '8.4.1', 'environment' => 'local']]))]);
    $context = stepContext();

    $result = (new AboutStep)->run($context);

    expect($result->status)->toBe(StepStatus::Ok)
        ->and($result->data)->toMatchArray(['name' => 'Shop', 'laravel' => '13.2.0', 'php' => '8.4.1'])
        ->and($context->preflightDir.'/about.json')->toBeFile();
});

it('groups pint results by rule', function () {
    $context = stepContext(project: fixture('project'));

    Process::fake(['*pint*' => Process::result('{"tool":"pint","result":"fail","files":[{"path":"app/A.php","fixers":["braces","no_unused_imports"]},{"path":"app/B.php","fixers":["braces"]}]}', exitCode: 1)]);

    $result = (new PintStep)->run($context);

    expect($result->data)->toMatchArray(['files_with_issues' => 2, 'rules' => 2])
        ->and($context->readJson('pint-grouped.json')['rules'][0])->toBe(['rule' => 'braces', 'files' => 2, 'examples' => ['app/A.php', 'app/B.php']]);
});

it('skips pint with --without', function () {
    expect((new PintStep)->run(stepContext(['pint']))->reason)->toBe(SkipReason::Without);
});

it('writes routes and route facts', function () {
    Process::fake(['*route:list*' => Process::result(file_get_contents(fixture('tools/routes.json')))]);
    $context = stepContext();

    $result = (new RoutesStep)->run($context);

    expect($result->data['count'])->toBe(8)
        ->and($context->readJson('route-facts.json'))->toHaveKey('unauthenticated_write');
});

it('diffs translations', function () {
    $context = stepContext();

    $result = (new TranslationsStep('en'))->run($context);

    expect($result->data)->toMatchArray(['locales' => ['de', 'en'], 'missing' => 3])
        ->and($context->readJson('translations.json')['directory'])->toBe('lang');
});

it('runs phpstan once and reads level and baseline', function () {
    Process::fake([
        '*dump-parameters*' => Process::result('{"level": 6}'),
        '*phpstan*analyse*' => Process::result(file_get_contents(fixture('tools/phpstan.json')), exitCode: 1),
    ]);
    $context = stepContext();

    $result = (new PhpstanStep)->run($context);

    expect($result->data)->toMatchArray(['level' => '6', 'level_source' => 'phpstan.neon', 'errors' => 4, 'baselined' => 4])
        ->and($context->readJson('phpstan/phpstan-grouped.json')['level'])->toBe('6');
});

it('marks phpstan as error when it produces no JSON', function () {
    Process::fake(['*' => Process::result('', 'PHP Fatal error: Allowed memory size exhausted', 255)]);

    $result = (new PhpstanStep)->run(stepContext());

    expect($result->status)->toBe(StepStatus::Error)
        ->and($result->error)->toContain('Allowed memory size');
});

it('runs composer tools and writes the dependency facts', function () {
    Process::fake([
        '*composer*audit*' => Process::result(file_get_contents(fixture('tools/composer-audit.json')), exitCode: 1),
        '*composer*outdated*' => Process::result(file_get_contents(fixture('tools/composer-outdated.json'))),
        '*composer*licenses*' => Process::result(file_get_contents(fixture('tools/composer-licenses.json'))),
        '*composer*show*' => Process::result('{"installed": []}'),
        '*composer*validate*' => Process::result('./composer.json is valid'),
        '*composer*why*' => Process::result('laravel/framework v13.2.0 requires guzzlehttp/psr7 (^2.0)'),
    ]);
    $context = stepContext();

    $result = (new ComposerStep)->run($context);

    expect($result->status)->toBe(StepStatus::Ok)
        ->and($result->data)->toMatchArray(['advisories' => 1, 'advisory_packages' => 1, 'abandoned' => 2, 'lock_in_sync' => true])
        ->and($context->readJson('composer/dependency-facts.json')['advisories'][0]['via'])->toBe(['laravel/framework'])
        ->and($context->preflightDir.'/composer/usage-map.json')->toBeFile();
})->skip(fn () => (new ProjectInspector(fixture('project')))->findExecutable('composer') === null, 'composer not in PATH');

it('skips browser tests quietly when there are none', function () {
    $project = tempDir('nobrowser');
    file_put_contents($project.'/phpunit.xml', '<phpunit><testsuites><testsuite name="Feature"><directory>tests/Feature</directory></testsuite></testsuites></phpunit>');
    $context = stepContext(project: $project);

    $result = (new BrowserStep(safeGuard($context->project)))->run($context);

    expect($result->reason)->toBe(SkipReason::NoBrowserTests)
        ->and($result->warnings)->toBe([]);
});

it('skips browser tests when the plugin or playwright is missing', function () {
    $context = stepContext();

    expect((new BrowserStep(safeGuard($context->project)))->run($context)->reason)->toBe(SkipReason::PluginMissing);
});

it('skips browser tests with --without', function () {
    $context = stepContext(['browser']);

    expect((new BrowserStep(safeGuard($context->project)))->run($context)->reason)->toBe(SkipReason::Without);
});

it('runs tests in the background without the browser suite and condenses the results', function () {
    Process::fake(['*artisan*test*' => Process::result('Tests: 6')]);
    $context = stepContext(['coverage']);
    $context->writeJson('routes.json', json_decode(file_get_contents(fixture('tools/routes.json')), true));
    $step = new TestsStep(safeGuard($context->project));

    $process = $step->start($context);
    copy(fixture('tools/junit.xml'), $context->path('tests/junit.xml'));
    $result = $step->finish($context, $process->wait());

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '--testsuite=Unit,Feature')
        && str_contains(implode(' ', (array) $process->command), '--parallel')
        && ! str_contains(implode(' ', (array) $process->command), '--coverage-clover'));

    expect($process)->toBeInstanceOf(InvokedProcess::class)
        ->and($result->data)->toMatchArray(['total' => 6, 'failed' => 2, 'errors' => 1, 'coverage_available' => false, 'suites' => ['Unit', 'Feature']])
        ->and($result->data['database']['safe'])->toBeTrue()
        ->and($context->preflightDir.'/tests/route-test-map.json')->toBeFile();
});

it('refuses to run tests against the app database', function () {
    $project = tempDir('shared');
    file_put_contents($project.'/phpunit.xml', '<phpunit/>');
    file_put_contents($project.'/.env', "DB_CONNECTION=mysql\nDB_DATABASE=shop\nDB_HOST=127.0.0.1\n");
    $context = stepContext(project: $project);

    $result = (new TestsStep(safeGuard($context->project)))->start($context);

    expect($result)->toBeInstanceOf(StepResult::class)
        ->and($result->reason)->toBe(SkipReason::SharedDatabase)
        ->and($result->step)->toBe(PreflightStep::Tests);
});
