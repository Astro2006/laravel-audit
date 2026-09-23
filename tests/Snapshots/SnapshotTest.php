<?php

use Astro2006\LaravelAudit\Agents\DependenciesAgent;
use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Snapshots\ComposerSnapshot;
use Astro2006\LaravelAudit\Snapshots\CoverageSnapshot;
use Astro2006\LaravelAudit\Snapshots\DatabaseSchemaSnapshot;
use Astro2006\LaravelAudit\Snapshots\PhpstanSnapshot;
use Astro2006\LaravelAudit\Snapshots\RouteTestsSnapshot;
use Astro2006\LaravelAudit\Snapshots\SnapshotWriter;
use Astro2006\LaravelAudit\Snapshots\StatusSnapshot;
use Astro2006\LaravelAudit\Snapshots\TestsSnapshot;
use Astro2006\LaravelAudit\Support\Json;

/** @param array<string, array<mixed>> $files relative path => JSON */
function snapshotPreflight(array $files, ?StepResults $steps = null): PreflightSummary
{
    $dir = tempDir('snapshots');

    foreach ($files as $path => $data) {
        @mkdir(dirname($dir.'/'.$path), 0777, true);
        Json::writeFile($dir.'/'.$path, $data);
    }

    return new PreflightSummary('run', GateDecision::Continued, $steps ?? new StepResults, $dir);
}

function failureGroups(int $count): array
{
    return array_map(fn (int $i): array => [
        'exception' => 'Illuminate\\Database\\QueryException',
        'message' => "SQLSTATE[42S02]: table orders_{$i} not found\n#0 app/Services/OrderService.php(12)",
        'count' => 2,
        'tests' => ["OrderTest::it stores {$i}"],
        'files' => ["tests/Feature/OrderTest{$i}.php"],
    ], range(1, $count));
}

it('puts metrics, IDs and paths in the prompt and the message only in the detail file', function () {
    $preflight = snapshotPreflight(['tests/tests-condensed.json' => [
        'total' => 2450, 'passed' => 2442, 'failed' => 8, 'errors' => 0, 'skipped' => 1, 'duration_s' => 12.3,
        'failure_groups' => failureGroups(1),
        'skipped_tests' => [['test' => 'SlowTest::it waits', 'reason' => 'flaky', 'file' => 'tests/SlowTest.php']],
        'slowest' => [['test' => 'SlowTest::it waits', 'seconds' => 4.2]],
    ]]);

    $prompt = (new SnapshotWriter([new TestsSnapshot]))->write($preflight, 'storage/audit/run/preflight')['tests'];
    $details = file_get_contents($preflight->directory.'/details/tests/T-01.md');

    expect($prompt)->toContain('### Tests — 🔴 FAILED')
        ->toContain('Total 2450 · Passed 2442 · Failed 8 · Skipped 1')
        ->toContain('| T-01 | 2 | QueryException | tests/Feature/OrderTest1.php |')
        ->toContain('Details per ID: `storage/audit/run/preflight/details/tests/<ID>.md`')
        ->toContain('Skipped tests: `storage/audit/run/preflight/details/tests/skipped.md`')
        ->not->toContain('SQLSTATE')
        ->and($details)->toContain('SQLSTATE[42S02]: table orders_1 not found')
        ->toContain('Illuminate\\Database\\QueryException')
        ->and($preflight->directory.'/snapshots/tests.md')->toBeFile()
        ->and(file_get_contents($preflight->directory.'/details/tests/skipped.md'))->toContain('flaky');
});

it('caps the rows in the prompt and lists every row in _all.md', function () {
    $preflight = snapshotPreflight(['tests/tests-condensed.json' => ['total' => 40, 'failed' => 20, 'failure_groups' => failureGroups(20)]]);

    $prompt = (new SnapshotWriter([new TestsSnapshot], maxRows: 5))->write($preflight, 'pre')['tests'];

    expect($prompt)->toContain('| T-05 |')->not->toContain('| T-06 |')
        ->toContain('… 15 more rows: `pre/details/tests/_all.md`')
        ->and(file_get_contents($preflight->directory.'/details/tests/_all.md'))->toContain('| T-20 |')
        ->and($preflight->directory.'/details/tests/T-20.md')->toBeFile();
});

it('marks baselined PHPStan identifiers and keeps the examples in the details', function () {
    $preflight = snapshotPreflight(['phpstan/phpstan-grouped.json' => [
        'level' => '6', 'errors' => 3, 'baselined' => 12,
        'identifiers' => [['identifier' => 'argument.type', 'count' => 3, 'file_count' => 2, 'top_files' => [['file' => 'app/A.php', 'count' => 2]], 'examples' => [['file' => 'app/A.php', 'line' => 7, 'message' => 'Parameter #1 expects int.']]]],
        'baselined_identifiers' => [['identifier' => 'missingType.iterableValue', 'count' => 12, 'file_count' => 4]],
    ]]);

    $prompt = (new SnapshotWriter([new PhpstanSnapshot]))->write($preflight, 'pre')['phpstan'];

    expect($prompt)->toContain('### PHPStan (level 6) — 🔴 3 errors')
        ->toContain('| P-01 | argument.type | 3 | 2 | open |')
        ->toContain('| P-02 | missingType.iterableValue | 12 | 4 | baselined |')
        ->not->toContain('Parameter #1')
        ->and(file_get_contents($preflight->directory.'/details/phpstan/P-01.md'))->toContain('`app/A.php:7` — Parameter #1 expects int.');
});

it('does not call PHPStan clean when it could not analyse everything', function () {
    $preflight = snapshotPreflight(['phpstan/phpstan-grouped.json' => [
        'level' => '6', 'errors' => 0, 'baselined' => 0, 'identifiers' => [], 'baselined_identifiers' => [],
        'general_errors' => ['Child process error: PHPStan process crashed because it reached configured PHP memory limit: 128M'],
    ]]);

    $prompt = (new SnapshotWriter([new PhpstanSnapshot]))->write($preflight, 'pre')['phpstan'];

    expect($prompt)->toContain('### PHPStan (level 6) — 🔴 PHPStan could not analyse everything, see general errors')
        ->not->toContain('no errors');
});

it('adds the usage map to the details of a composer fact', function () {
    $preflight = snapshotPreflight([
        DependenciesAgent::FACTS_FILE => [
            'advisories' => [['package' => 'guzzlehttp/psr7', 'severity' => 'medium', 'cve' => 'CVE-2023-29197', 'title' => 'Header injection']],
            'unused_candidates' => [['package' => 'acme/unused-lib', 'section' => 'require']],
            'validate' => ['ok' => true, 'lock_in_sync' => false, 'output' => 'The lock file is not up to date.'],
        ],
        'composer/usage-map.json' => ['packages' => ['acme/unused-lib' => ['namespaces' => ['Acme\\Unused\\'], 'prod_refs' => 0]]],
    ]);

    $prompt = (new SnapshotWriter([new ComposerSnapshot]))->write($preflight, 'pre')['composer'];

    expect($prompt)->toContain('| D-01 | advisories | guzzlehttp/psr7 | medium CVE-2023-29197 |')
        ->toContain('| D-02 | unused_candidates | acme/unused-lib | require |')
        ->toContain('lock in sync no')
        ->not->toContain('Header injection')
        ->and(file_get_contents($preflight->directory.'/details/composer/D-02.md'))->toContain('**Usage**')->toContain('Acme\\Unused\\')
        ->and(file_get_contents($preflight->directory.'/details/composer/composer.md'))->toContain('The lock file is not up to date.');
});

it('lists the preflight steps and skips sources without data', function () {
    $preflight = snapshotPreflight([], new StepResults([
        StepResult::ok(PreflightStep::Tests, new TestsDTO(total: 3, passed: 3)),
        StepResult::skipped(PreflightStep::Browser, SkipReason::NoBrowserTests),
        StepResult::error(PreflightStep::Phpstan, "PHPStan crashed:\nmemory exhausted"),
    ]));

    $rendered = (new SnapshotWriter([new StatusSnapshot, new TestsSnapshot]))->write($preflight, 'pre');

    expect($rendered)->toHaveKeys(['status'])->not->toHaveKey('tests')
        ->and($rendered['status'])->toContain('1 ok (tests) · 1 skipped · 1 error')
        ->toContain('| S-01 | browser | skipped | no-browser-tests |')
        ->toContain('| S-02 | phpstan | error | see details |')
        ->not->toContain('| tests | ok |')
        ->not->toContain('memory exhausted')
        ->and(file_get_contents($preflight->directory.'/details/status/S-02.md'))->toContain('memory exhausted')
        ->and((new SnapshotWriter([new StatusSnapshot]))->write(new PreflightSummary('run', GateDecision::NotRun, new StepResults), 'pre'))->toBe([]);
});

it('lists flagged tables first and keeps the columns in the detail file', function () {
    $preflight = snapshotPreflight(['database/schema.json' => [
        'migrations' => [
            ['file' => 'database/migrations/2024_01_01_create_users.php', 'tables' => ['users'], 'flags' => []],
            ['file' => 'database/migrations/2024_01_02_create_orders.php', 'tables' => ['orders'], 'flags' => ['no down()']],
        ],
        'tables' => [
            'users' => ['migrations' => ['database/migrations/2024_01_01_create_users.php'], 'columns' => ['id' => 'id', 'email' => 'string'], 'indexed' => ['id'], 'foreign' => [], 'unique' => ['email'], 'flags' => []],
            'orders' => ['migrations' => ['database/migrations/2024_01_02_create_orders.php'], 'columns' => ['id' => 'id', 'shop_id' => 'unsignedBigInteger'], 'indexed' => ['id'], 'foreign' => [], 'unique' => [], 'flags' => ['`shop_id` has no foreign key constraint']],
        ],
    ]]);

    $prompt = (new SnapshotWriter([new DatabaseSchemaSnapshot]))->write($preflight, 'pre')['database-schema'];

    expect($prompt)->toContain('### Database schema — 2 tables from 2 migrations (static read)')
        ->toContain('`database/migrations/2024_01_02_create_orders.php`: no down()')
        ->toContain('| Y-01 | `orders` | 2 | 1 | 0 | `shop_id` has no foreign key constraint |')
        ->toContain('| Y-02 | `users` | 2 | 2 | 0 | – |')
        ->and(file_get_contents($preflight->directory.'/details/database-schema/Y-01.md'))->toContain('`shop_id` unsignedBigInteger');
});

it('leaves enums and exceptions out of the coverage rows', function () {
    $preflight = snapshotPreflight(['tests/coverage-by-class.json' => ['lines_pct' => 90.0, 'classes' => [
        ['class' => 'App\\Enums\\Status', 'file' => 'app/Enums/Status.php', 'lines_pct' => 0.0],
        ['class' => 'App\\Exceptions\\Boom', 'file' => 'app/Exceptions/Boom.php', 'lines_pct' => 0.0],
        ['class' => 'App\\Http\\Controllers\\LogoutController', 'file' => 'app/Http/Controllers/LogoutController.php', 'lines_pct' => 0.0],
    ]]]);

    $prompt = (new SnapshotWriter([new CoverageSnapshot]))->write($preflight, 'pre')['coverage'];

    expect($prompt)->toContain('Classes 3 (2 enums and exceptions not listed)')
        ->toContain('LogoutController')
        ->not->toContain('Enums\\Status')
        ->not->toContain('Boom');
});

it('leaves package routes out of the route test map', function () {
    $preflight = snapshotPreflight(['tests/route-test-map.json' => ['routes' => [
        ['method' => 'POST', 'uri' => 'livewire-03fdddf0/update', 'action' => 'Livewire\\Mechanisms\\HandleRequests\\HandleRequests@handleUpdate', 'tests' => []],
        ['method' => 'POST', 'uri' => '_boost/browser-logs', 'action' => 'Closure', 'tests' => []],
        ['method' => 'DELETE', 'uri' => 'locations/home', 'action' => 'App\\Http\\Controllers\\HomeLocationController@destroy', 'tests' => []],
    ]]]);

    $prompt = (new SnapshotWriter([new RouteTestsSnapshot]))->write($preflight, 'pre')['route-tests'];

    expect($prompt)->toContain('1 of 1 routes without a test')
        ->toContain('Package routes 2 (not listed)')
        ->toContain('DELETE locations/home')
        ->not->toContain('livewire');
});
