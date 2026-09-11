<?php

use Astro2006\LaravelAudit\Preflight\Condensers\CoverageCondenser;
use Astro2006\LaravelAudit\Preflight\Condensers\DependencyFacts;
use Astro2006\LaravelAudit\Preflight\Condensers\JunitCondenser;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanConfig;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanGrouper;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteFacts;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteTestMapper;
use Astro2006\LaravelAudit\Preflight\Condensers\TranslationDiff;
use Astro2006\LaravelAudit\Preflight\Condensers\UsageMapBuilder;
use Astro2006\LaravelAudit\Support\ProjectInspector;

it('condenses a junit report', function () {
    $result = (new JunitCondenser)->condense(fixture('tools/junit.xml'), '/app');

    expect($result)
        ->total->toBe(6)
        ->passed->toBe(2)
        ->failed->toBe(2)
        ->errors->toBe(1)
        ->skipped->toBe(1)
        ->environment_error->toBeFalse()
        ->and($result['failure_groups'][0])->toMatchArray([
            'exception' => 'ErrorException',
            'message' => 'Undefined array key "discount"',
            'count' => 2,
            'files' => ['tests/Feature/CheckoutTest.php'],
        ])
        ->and($result['failure_groups'][1]['tests'])->toBe(['Tests\Feature\OrderTest::it lists orders'])
        ->and($result['skipped_tests'][0])->toBe(['test' => 'Tests\Feature\ExportTest::it exports csv', 'reason' => 'TODO after refactor', 'file' => 'tests/Feature/ExportTest.php'])
        ->and($result['slowest'][0])->toBe(['test' => 'Tests\Feature\OrderTest::it lists orders', 'seconds' => 4.1]);
});

it('flags a broken environment when almost everything fails for one reason', function () {
    $cases = str_repeat('<testcase name="t" class="A" time="0.1"><error type="PDOException">could not find driver</error></testcase>', 10);
    $path = tempDir('junit').'/junit.xml';
    file_put_contents($path, "<testsuites><testsuite>{$cases}</testsuite></testsuites>");

    expect((new JunitCondenser)->condense($path, '/app')['environment_error'])->toBeTrue()
        ->and((new JunitCondenser)->condense('/missing.xml', '/app'))->toBeNull();
});

it('condenses clover coverage for app classes only, lowest first', function () {
    $result = (new CoverageCondenser)->condense(fixture('tools/clover.xml'), '/app');

    expect($result['files'])->toBe(2)
        ->and($result['lines_pct'])->toBe(60.0)
        ->and($result['classes'][0])->toBe([
            'class' => 'App\Services\OrderService',
            'file' => 'app/Services/OrderService.php',
            'lines_pct' => 40.0,
            'statements' => 10,
            'uncovered_methods' => ['refund'],
        ])
        ->and($result['classes'][1]['class'])->toBe('App\Models\User');
});

it('finds route candidates', function () {
    $routes = json_decode(file_get_contents(fixture('tools/routes.json')), true);
    $facts = (new RouteFacts(fn (string $action) => ! str_contains($action, 'CheckoutController')))->facts($routes);

    expect(array_column($facts['unauthenticated_write'], 'uri'))->toBe(['/orders/{order}', '/contact'])
        ->and(array_column($facts['state_changing_get'], 'uri'))->toBe(['/orders/{order}/delete'])
        ->and(array_column($facts['missing_throttle'], 'uri'))->toBe(['/contact'])
        ->and(array_column($facts['debug_routes'], 'uri'))->toBe(['/telescope/{view?}'])
        ->and(array_column($facts['broken_routes'], 'uri'))->toBe(['/checkout'])
        ->and($facts['flagged'])->toBe(6);
});

it('maps routes to tests by name, uri and controller', function () {
    $routes = json_decode(file_get_contents(fixture('tools/routes.json')), true);
    $map = (new RouteTestMapper)->map($routes, [
        'tests/Feature/OrderTest.php' => file_get_contents(fixture('project/tests/Feature/OrderTest.php')),
        'tests/Browser/CheckoutTest.php' => file_get_contents(fixture('project/tests/Browser/CheckoutTest.php')),
    ]);

    $byUri = collect($map['routes'])->keyBy('uri');

    expect($byUri['/orders']['tests'])->toBe(['tests/Feature/OrderTest.php'])
        ->and($byUri['/orders']['matched_by'])->toBe(['name'])
        ->and($byUri['/orders/{order}']['matched_by'])->toBe(['uri'])
        ->and($byUri['/checkout']['tests'])->toBe(['tests/Browser/CheckoutTest.php'])
        ->and($byUri['/contact']['tests'])->toBe([])
        ->and($map['untested'])->toBe(4);
});

it('lists translation keys missing in other locales', function () {
    $diff = (new TranslationDiff)->diff(fixture('project/lang'), 'en');

    expect($diff['locales'])->toBe(['de', 'en'])
        ->and($diff['missing'])->toBe(['de' => ['count' => 3, 'keys' => ['auth.nested.deep', 'auth.throttle', 'json:Checkout']]]);
});

it('groups phpstan errors and reads the baseline', function () {
    $config = new PhpstanConfig(fixture('project/phpstan.neon'));
    $grouped = (new PhpstanGrouper)->group(json_decode(file_get_contents(fixture('tools/phpstan.json')), true), $config->baselineEntries(), '/app');

    expect($config->level())->toBe('6')
        ->and($grouped['errors'])->toBe(4)
        ->and($grouped['baselined'])->toBe(4)
        ->and($grouped['identifiers'][0])->toMatchArray(['identifier' => 'missingType.return', 'count' => 2, 'file_count' => 1, 'top_files' => ['app/Services/OrderService.php' => 2]])
        ->and(array_column($grouped['identifiers'], 'identifier'))->toContain('unknown')
        ->and($grouped['baselined_identifiers'][0])->toMatchArray(['identifier' => 'missingType.parameter', 'count' => 3, 'baselined' => true]);
});

it('builds the usage map from class references', function () {
    $map = (new UsageMapBuilder(new ProjectInspector(fixture('project'))))->build();

    expect($map['packages']['acme/used-lib'])->toMatchArray(['section' => 'require', 'prod_refs' => 2, 'test_refs' => 0, 'top_files' => ['app/Http/Controllers/OrderController.php']])
        ->and($map['packages']['acme/unused-lib']['prod_refs'])->toBe(0)
        ->and($map['packages']['acme/test-only'])->toMatchArray(['prod_refs' => 0, 'test_refs' => 1])
        ->and($map['packages']['acme/dev-tool'])->toMatchArray(['section' => 'require-dev', 'prod_refs' => 1, 'binaries' => ['dev-tool']])
        ->and($map['transitive_usage'])->toHaveKey('acme/transitive-lib');
});

it('derives dependency facts and candidates', function () {
    $project = new ProjectInspector(fixture('project'));
    $facts = (new DependencyFacts)->build(
        project: $project,
        audit: json_decode(file_get_contents(fixture('tools/composer-audit.json')), true),
        outdated: json_decode(file_get_contents(fixture('tools/composer-outdated.json')), true),
        licenses: json_decode(file_get_contents(fixture('tools/composer-licenses.json')), true),
        validateOutput: "./composer.json is valid but your composer.lock has some errors\nThe lock file is not up to date with the latest changes in composer.json.",
        validateExitCode: 1,
        usageMap: (new UsageMapBuilder($project))->build(),
        why: ['guzzlehttp/psr7' => ['laravel/framework v13.2.0 requires guzzlehttp/psr7 (^2.0)']],
        licenseAllowlist: ['MIT'],
    );

    expect($facts['advisories'][0])->toMatchArray(['package' => 'guzzlehttp/psr7', 'installed' => '2.4.1', 'direct' => false, 'via' => ['laravel/framework'], 'severity' => 'medium'])
        ->and($facts['abandoned'])->toBe([
            ['package' => 'acme/test-only', 'replacement' => null],
            ['package' => 'acme/unused-lib', 'replacement' => 'acme/new-lib'],
        ])
        ->and(array_column($facts['outdated_major'], 'package'))->toBe(['acme/used-lib', 'acme/test-only'])
        ->and(array_column($facts['misplaced_candidates'], 'package'))->toBe(['acme/test-only', 'acme/dev-tool'])
        ->and(array_column($facts['unused_candidates'], 'package'))->toBe(['acme/unused-lib'])
        ->and(array_column($facts['shadow_candidates'], 'package'))->toBe(['acme/transitive-lib'])
        ->and(array_column($facts['licenses_flagged'], 'package'))->toBe(['acme/unused-lib', 'acme/nolicense'])
        ->and(array_column($facts['constraints_flagged'], 'package'))->toBe(['acme/test-only'])
        ->and($facts['composer_config'])->toContain('config.allow-plugins is true for every plugin')
        ->and($facts['validate']['lock_in_sync'])->toBeFalse()
        ->and(DependencyFacts::packagesNeedingWhy(['advisories' => ['guzzlehttp/psr7' => []], 'abandoned' => ['acme/unused-lib' => null]], $project))->toBe(['guzzlehttp/psr7']);
});
