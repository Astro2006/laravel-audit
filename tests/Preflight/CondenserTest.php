<?php

use Astro2006\LaravelAudit\Data\Composer\AbandonedPackage;
use Astro2006\LaravelAudit\Data\Composer\DependencyFactsReport;
use Astro2006\LaravelAudit\Data\Composer\PackageCandidate;
use Astro2006\LaravelAudit\Data\Phpstan\FileCount;
use Astro2006\LaravelAudit\Data\Phpstan\PhpstanMessage;
use Astro2006\LaravelAudit\Data\Routes\RouteDefinition;
use Astro2006\LaravelAudit\Data\Tests\ClassCoverage;
use Astro2006\LaravelAudit\Preflight\Condensers\CoverageCondenser;
use Astro2006\LaravelAudit\Preflight\Condensers\DependencyFacts;
use Astro2006\LaravelAudit\Preflight\Condensers\JunitCondenser;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanConfig;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanGrouper;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteFacts;
use Astro2006\LaravelAudit\Preflight\Condensers\RouteTestMapper;
use Astro2006\LaravelAudit\Preflight\Condensers\TranslationDiff;
use Astro2006\LaravelAudit\Preflight\Condensers\UsageMapBuilder;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function fixtureRoutes(): array
{
    return RouteDefinition::listFromJson(Json::readFile(fixture('tools/routes.json')));
}

function uris(array $routes): array
{
    return array_map(fn (RouteDefinition $route) => $route->uri, $routes);
}

it('condenses a junit report', function () {
    $report = (new JunitCondenser)->condense(fixture('tools/junit.xml'), '/app');
    [$coupon, $orders] = $report->failureGroups;
    [$skipped] = $report->skippedTests;
    [$slowest] = $report->slowest;

    expect($report)
        ->total->toBe(6)
        ->passed->toBe(2)
        ->failed->toBe(2)
        ->errors->toBe(1)
        ->skipped->toBe(1)
        ->environmentError->toBeFalse()
        ->and($coupon)->exception->toBe('ErrorException')
        ->message->toBe('Undefined array key "discount"')
        ->count->toBe(2)
        ->files->toBe(['tests/Feature/CheckoutTest.php'])
        ->and($orders->tests)->toBe(['Tests\Feature\OrderTest::it lists orders'])
        ->and($skipped)->test->toBe('Tests\Feature\ExportTest::it exports csv')->reason->toBe('TODO after refactor')
        ->and($slowest)->test->toBe('Tests\Feature\OrderTest::it lists orders')->seconds->toBe(4.1)
        ->and($report->topFailure())->toBe('ErrorException: Undefined array key "discount"')
        ->and($report->toArray())->toHaveKeys(['failure_groups', 'skipped_tests', 'slowest', 'environment_error']);
});

it('flags a broken environment when almost everything fails for one reason', function () {
    $cases = str_repeat('<testcase name="t" class="A" time="0.1"><error type="PDOException">could not find driver</error></testcase>', 10);
    $path = tempDir('junit').'/junit.xml';
    file_put_contents($path, "<testsuites><testsuite>{$cases}</testsuite></testsuites>");

    expect((new JunitCondenser)->condense($path, '/app')->environmentError)->toBeTrue()
        ->and((new JunitCondenser)->condense('/missing.xml', '/app'))->toBeNull();
});

it('skips the test identifier phpunit 12 puts before the failure message', function () {
    $path = tempDir('junit').'/junit.xml';
    file_put_contents($path, <<<'XML'
        <testsuites><testsuite>
        <testcase name="test_home" class="Tests\Feature\ExampleTest" file="/app/tests/Feature/ExampleTest.php" time="0.4"><failure type="PHPUnit\Framework\ExpectationFailedException">Tests\Feature\ExampleTest::test_home
        Expected response status code [200] but received 500.
        Failed asserting that 500 is identical to 200.</failure></testcase>
        </testsuite></testsuites>
        XML);

    [$group] = (new JunitCondenser)->condense($path, '/app')->failureGroups;

    expect($group->message)->toBe('Expected response status code [200] but received 500.');
});

it('does not flag the signed local storage routes of the framework', function () {
    $routes = RouteDefinition::listFromJson([
        ['method' => 'PUT', 'uri' => 'storage/{path}', 'name' => 'storage.local.upload', 'action' => 'Closure', 'middleware' => []],
        ['method' => 'PUT', 'uri' => 'files/{path}', 'name' => 'files.upload', 'action' => 'Closure', 'middleware' => []],
    ]);

    [$flagged] = (new RouteFacts)->facts($routes)->unauthenticatedWrite;

    expect((new RouteFacts)->facts($routes)->unauthenticatedWrite)->toHaveCount(1)
        ->and($flagged->name)->toBe('files.upload');
});

it('condenses clover coverage for app classes only, lowest first', function () {
    $report = (new CoverageCondenser)->condense(fixture('tools/clover.xml'), '/app');
    [$lowest, $highest] = $report->classes;

    expect($report->classes)->toHaveCount(2)
        ->and($report->linesPct)->toBe(60.0)
        ->and($lowest)->toEqual(new ClassCoverage('App\Services\OrderService', 'app/Services/OrderService.php', 40.0, 10, ['refund']))
        ->and($highest->class)->toBe('App\Models\User');
});

it('finds route candidates', function () {
    $facts = (new RouteFacts(fn (string $action) => ! str_contains($action, 'CheckoutController')))->facts(fixtureRoutes());

    expect(uris($facts->unauthenticatedWrite))->toBe(['/orders/{order}', '/contact'])
        ->and(uris($facts->stateChangingGet))->toBe(['/orders/{order}/delete'])
        ->and(uris($facts->missingThrottle))->toBe(['/contact'])
        ->and(uris($facts->debugRoutes))->toBe(['/telescope/{view?}'])
        ->and(uris($facts->brokenRoutes))->toBe(['/checkout'])
        ->and($facts->flagged())->toBe(6);
});

it('maps routes to tests by name, uri and controller', function () {
    $map = (new RouteTestMapper)->map(fixtureRoutes(), [
        'tests/Feature/OrderTest.php' => file_get_contents(fixture('project/tests/Feature/OrderTest.php')),
        'tests/Browser/CheckoutTest.php' => file_get_contents(fixture('project/tests/Browser/CheckoutTest.php')),
    ]);

    expect($map->forUri('/orders')?->tests)->toBe(['tests/Feature/OrderTest.php'])
        ->and($map->forUri('/orders')?->matchedBy)->toBe(['name'])
        ->and($map->forUri('/orders/{order}')?->matchedBy)->toBe(['uri'])
        ->and($map->forUri('/checkout')?->tests)->toBe(['tests/Browser/CheckoutTest.php'])
        ->and($map->forUri('/contact')?->tests)->toBe([])
        ->and($map->untested())->toBe(4);
});

it('lists translation keys missing in other locales', function () {
    $report = (new TranslationDiff)->diff(fixture('project/lang'), 'en');
    [$german] = $report->missing;

    expect($report->locales)->toBe(['de', 'en'])
        ->and($german)->locale->toBe('de')->count->toBe(3)->keys->toBe(['auth.nested.deep', 'auth.throttle', 'json:Checkout'])
        ->and($report->missingCount())->toBe(3);
});

it('groups phpstan errors and reads the baseline', function () {
    $config = new PhpstanConfig(fixture('project/phpstan.neon'));
    $messages = PhpstanMessage::listFromJson(Json::readFile(fixture('tools/phpstan.json')), '/app');
    $report = (new PhpstanGrouper)->group('6', $messages, $config->baselineEntries());
    [$top] = $report->identifiers;
    [$baselined] = $report->baselinedIdentifiers;

    expect($config->level())->toBe('6')
        ->and($report->errors)->toBe(4)
        ->and($report->baselined)->toBe(4)
        ->and($top)->identifier->toBe('missingType.return')->count->toBe(2)->fileCount->toBe(1)
        ->and($top->topFiles)->toEqual([new FileCount('app/Services/OrderService.php', 2)])
        ->and($report->identifier('unknown'))->not->toBeNull()
        ->and($baselined)->identifier->toBe('missingType.parameter')->count->toBe(3)->baselined->toBeTrue();
});

it('builds the usage map from class references', function () {
    $map = (new UsageMapBuilder(new ProjectInspector(fixture('project'))))->build();

    expect($map->package('acme/used-lib'))->section->toBe('require')->prodRefs->toBe(2)->testRefs->toBe(0)->topFiles->toBe(['app/Http/Controllers/OrderController.php'])
        ->and($map->package('acme/unused-lib')?->prodRefs)->toBe(0)
        ->and($map->package('acme/test-only'))->prodRefs->toBe(0)->testRefs->toBe(1)
        ->and($map->package('acme/dev-tool'))->section->toBe('require-dev')->prodRefs->toBe(1)->binaries->toBe(['dev-tool'])
        ->and(array_map(fn ($usage) => $usage->name, $map->transitive))->toContain('acme/transitive-lib');
});

it('derives dependency facts and candidates', function () {
    $project = new ProjectInspector(fixture('project'));
    $facts = (new DependencyFacts)->build(
        project: $project,
        audit: Json::readFile(fixture('tools/composer-audit.json')),
        outdated: Json::readFile(fixture('tools/composer-outdated.json')),
        licenses: Json::readFile(fixture('tools/composer-licenses.json')),
        validateOutput: "./composer.json is valid but your composer.lock has some errors\nThe lock file is not up to date with the latest changes in composer.json.",
        validateExitCode: 1,
        usageMap: (new UsageMapBuilder($project))->build(),
        why: ['guzzlehttp/psr7' => ['laravel/framework v13.2.0 requires guzzlehttp/psr7 (^2.0)']],
        licenseAllowlist: ['MIT'],
    );
    [$advisory] = $facts->advisories;
    $packages = fn (array $items) => array_map(fn ($item) => $item->package, $items);

    expect($advisory)->package->toBe('guzzlehttp/psr7')->installed->toBe('2.4.1')->direct->toBeFalse()->via->toBe(['laravel/framework'])->severity->toBe('medium')
        ->and($facts->abandoned)->toEqual([new AbandonedPackage('acme/test-only', null), new AbandonedPackage('acme/unused-lib', 'acme/new-lib')])
        ->and($packages($facts->outdatedMajor))->toBe(['acme/used-lib', 'acme/test-only'])
        ->and($packages($facts->misplacedCandidates))->toBe(['acme/test-only', 'acme/dev-tool'])
        ->and($packages($facts->unusedCandidates))->toBe(['acme/unused-lib'])
        ->and($facts->shadowCandidates)->toEqual([new PackageCandidate('acme/transitive-lib', prodRefs: 1, topFiles: ['app/Http/Controllers/OrderController.php'])])
        ->and($packages($facts->licensesFlagged))->toBe(['acme/unused-lib', 'acme/nolicense'])
        ->and($packages($facts->constraintsFlagged))->toBe(['acme/test-only'])
        ->and($facts->composerConfig)->toContain('config.allow-plugins is true for every plugin')
        ->and($facts->validate->lockInSync)->toBeFalse()
        ->and($facts->advisoriesBySeverity())->toBe(['critical' => 0, 'high' => 0, 'medium' => 1, 'low' => 0])
        ->and(DependencyFacts::packagesNeedingWhy(['advisories' => ['guzzlehttp/psr7' => []], 'abandoned' => ['acme/unused-lib' => null]], $project))->toBe(['guzzlehttp/psr7']);
});

it('round-trips the dependency facts file', function () {
    $project = new ProjectInspector(fixture('project'));
    $facts = (new DependencyFacts)->build($project, Json::readFile(fixture('tools/composer-audit.json')), [], [], '', 0, (new UsageMapBuilder($project))->build(), [], ['MIT']);

    expect(DependencyFactsReport::fromArray($facts->toArray()))->toEqual($facts);
});
