<?php

use Astro2006\LaravelAudit\Eval\EvalReport;
use Astro2006\LaravelAudit\Eval\ExpectedSet;
use Astro2006\LaravelAudit\Run\Data\RunRecord;

function evalRun(string $id, array $findings, array $extra = []): RunRecord
{
    return RunRecord::fromArray([
        'run_id' => $id,
        'overlaps' => 1,
        'outcomes' => [array_merge([
            'audit' => 'backend',
            'status' => 'completed',
            'repaired' => false,
            'prompt_hash' => 'abc12345',
            'telemetry' => ['cost_usd' => 2.0, 'input_tokens' => 1000, 'output_tokens' => 100, 'turns' => 20, 'duration_s' => 300],
            'findings' => $findings,
            'warnings' => [],
        ], $extra)],
    ]);
}

function evalFinding(string $key, string $check, string $target, string $severity = 'high'): array
{
    return ['key' => $key, 'id' => 'BE-001', 'title' => 'T', 'check' => $check, 'severity' => $severity, 'targets' => [$target], 'locations' => [$target]];
}

function expectedSet(): ExpectedSet
{
    $path = tempDir('eval').'/expected.json';
    file_put_contents($path, json_encode([
        'planted' => [
            ['id' => 'P01', 'audit' => 'backend', 'check' => 'env-outside-config', 'file' => 'app/Services/Pay.php'],
            ['id' => 'P03', 'audit' => 'backend', 'check' => 'mass-assignment', 'file' => 'app/Services/OrderService.php', 'min_severity' => 'high'],
            ['id' => 'D01', 'audit' => 'dependencies', 'check' => 'vulnerable-dependency', 'package' => 'Guzzlehttp/Psr7'],
        ],
        'negative_controls' => [
            ['id' => 'N01', 'audit' => 'backend', 'check' => 'sql-injection', 'file' => 'app/Repositories/ReportRepository.php'],
        ],
    ]));

    return ExpectedSet::fromFile($path);
}

it('measures recall, severity, control hits, stability, format and cost', function () {
    $runs = [
        evalRun('r1', [
            evalFinding('k1', 'env-outside-config', 'app/Services/Pay.php'),
            evalFinding('k2', 'mass-assignment', 'app/Services/OrderService.php', 'medium'),
            evalFinding('k3', 'sql-injection', 'app/Repositories/ReportRepository.php'),
        ]),
        evalRun('r2', [
            evalFinding('k1', 'env-outside-config', 'app/Services/Pay.php'),
            evalFinding('k4', 'dead-code', 'app/Jobs/Old.php', 'low'),
        ], ['repaired' => true, 'warnings' => ['BE-002: location `x.php` does not exist in the project (possibly hallucinated).']]),
    ];

    $result = (new EvalReport)->evaluate(expectedSet(), $runs);
    $backend = $result->audit('backend');

    expect($backend)->not->toBeNull()
        ->planted->toBe(2)
        ->found->toBe([2, 1])
        ->severityOk->toBe([1, 1])
        ->controlHits->toBe([1, 0])
        ->completed->toBe(2)
        ->repaired->toBe(1)
        ->failed->toBe(0)
        ->hallucinatedLocations->toBe(1)
        ->missed->toBe([])
        ->and($backend->recall())->toBe(75.0)
        ->and($backend->stability)->jaccard->toBe(0.25)->inAllRuns->toBe(0.25)
        ->and($backend->cost)->costMean->toBe(2.0)->costMax->toBe(2.0)
        ->and(array_map(fn ($finding) => $finding->key, $backend->extraFindings))->toBe(['k4'])
        ->and($result->duplicates)->toBe([1, 1])
        ->and($result->controlHitsTotal())->toBe([1, 0]);
});

it('renders a markdown report with missed and extra findings', function () {
    $markdown = (new EvalReport)->render((new EvalReport)->evaluate(expectedSet(), [
        evalRun('r1', [evalFinding('k9', 'logic-bug', 'app/X.php')]),
    ]));

    expect($markdown)
        ->toContain('| backend | 0 % | 0 / 2 |')
        ->toContain('## Missed in every run — backend')
        ->toContain('- P01 (env-outside-config, app/Services/Pay.php)')
        ->toContain('## Other findings (last run) — backend')
        ->toContain('| BE-001 | logic-bug | high | T | app/X.php | |');
});

it('matches packages case-insensitively via the package key', function () {
    [$planted] = expectedSet()->plantedFor('dependencies');

    expect($planted->target)->toBe('package:guzzlehttp/psr7');
});
