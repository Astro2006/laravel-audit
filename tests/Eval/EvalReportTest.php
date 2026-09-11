<?php

use Astro2006\LaravelAudit\Eval\EvalReport;
use Astro2006\LaravelAudit\Eval\ExpectedSet;

function evalRun(string $id, array $findings, array $extra = []): array
{
    return [
        'run_id' => $id,
        'overlaps' => 1,
        'outcomes' => ['backend' => array_merge([
            'status' => 'completed',
            'repaired' => false,
            'prompt_hash' => 'abc12345',
            'telemetry' => ['cost_usd' => 2.0, 'input_tokens' => 1000, 'output_tokens' => 100, 'turns' => 20, 'duration_s' => 300],
            'findings' => $findings,
            'warnings' => [],
        ], $extra)],
    ];
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
    $backend = $result['audits']['backend'];

    expect($backend['planted'])->toBe(2)
        ->and($backend['found'])->toBe([2, 1])
        ->and($backend['recall'])->toBe(75.0)
        ->and($backend['severity_ok'])->toBe([1, 1])
        ->and($backend['control_hits'])->toBe([1, 0])
        ->and($backend['stability'])->toBe(['jaccard' => 0.25, 'in_all_runs' => 0.25])
        ->and($backend['format'])->toBe(['completed' => 2, 'repaired' => 1, 'failed' => 0, 'hallucinated_locations' => 1])
        ->and($backend['cost']['cost_usd'])->toBe(['mean' => 2.0, 'max' => 2.0])
        ->and($backend['missed'])->toBe([])
        ->and(array_column($backend['extra_findings'], 'key'))->toBe(['k4'])
        ->and($result['duplicates'])->toBe([1, 1]);
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
    expect(expectedSet()->plantedFor('dependencies')[0]->target)->toBe('package:guzzlehttp/psr7');
});
