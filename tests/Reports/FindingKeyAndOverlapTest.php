<?php

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\OverlapDetector;
use Astro2006\LaravelAudit\Reports\ReportWriter;

function makeFinding(string $id, string $check, array $locations): Finding
{
    return new Finding(
        id: $id,
        title: 'Title',
        severity: Severity::High,
        confidence: Confidence::Confirmed,
        category: Category::Security,
        check: $check,
        effort: Effort::S,
        locations: array_map(fn (string $raw) => Location::parse($raw), $locations),
        occurrences: 1,
        references: [],
        related: [],
        evidence: 'e',
        impact: 'i',
        recommendation: 'r',
    );
}

it('builds keys that ignore ids, line numbers and location order', function () {
    $a = makeFinding('BE-001', 'mass-assignment', ['app/A.php:10', 'app/B.php:3']);
    $b = makeFinding('BE-007', 'mass-assignment', ['app/B.php:99', 'app/A.php']);
    $c = makeFinding('BE-001', 'sql-injection', ['app/A.php:10', 'app/B.php:3']);

    expect($a->key('backend'))->toBe($b->key('backend'))
        ->and($a->key('backend'))->not->toBe($c->key('backend'))
        ->and($a->key('backend'))->not->toBe($a->key('frontend'))
        ->and($a->key('backend'))->toHaveLength(8);
});

it('keeps single keys and separates findings that would share one', function () {
    $single = makeFinding('BE-003', 'sql-injection', ['app/A.php:10']);
    $first = makeFinding('BE-001', 'mass-assignment', ['app/A.php:10']);
    $second = makeFinding('BE-002', 'mass-assignment', ['app/A.php:200']);

    [$a, $b, $c] = Finding::withUniqueKeys([$first, $second, $single]);

    expect($a->key('backend'))->not->toBe($b->key('backend'))
        ->and($c->key('backend'))->toBe($single->key('backend'))
        ->and($a->key('backend'))->toBe(Finding::withUniqueKeys([$first, $second])[0]->key('backend'));
});

it('separates findings on the same line by title', function () {
    $first = makeFinding('BE-001', 'mass-assignment', ['app/A.php:10']);
    $second = new Finding('BE-002', 'Another issue', $first->severity, $first->confidence, $first->category, $first->check, $first->effort, $first->locations, 1, [], [], 'e', 'i', 'r');

    [$a, $b] = Finding::withUniqueKeys([$first, $second]);

    expect($a->key('backend'))->not->toBe($b->key('backend'));
});

it('detects possible duplicates across audits', function () {
    $overlaps = (new OverlapDetector)->detect([
        'backend' => [makeFinding('BE-001', 'mass-assignment', ['app/Http/Controllers/OrderController.php:50-60'])],
        'frontend' => [
            makeFinding('FE-001', 'route-authorization', ['app/Http/Controllers/OrderController.php:57']),
            makeFinding('FE-002', 'xss', ['resources/views/a.blade.php:3']),
        ],
        'dependencies' => [makeFinding('DEP-001', 'abandoned-package', ['package:a/b'])],
    ]);

    [$overlap] = $overlaps;

    expect($overlaps)->toHaveCount(1)
        ->and($overlap->findingA->id)->toBe('BE-001')
        ->and($overlap->findingB->id)->toBe('FE-001');
});

it('detects duplicates between tasks of one audit, but not inside one task', function () {
    $findings = [
        makeFinding('BE-001', 'sql-injection', ['app/Http/Controllers/OrderController.php:10']),
        makeFinding('BE-002', 'command-injection', ['app/Http/Controllers/OrderController.php:12']),
        makeFinding('BE-003', 'authorization-gap', ['app/Http/Controllers/OrderController.php']),
    ];
    $tasks = ['backend' => ['BE-001' => 'backend.injection', 'BE-002' => 'backend.injection', 'BE-003' => 'backend.auth']];

    $overlaps = (new OverlapDetector)->detect(['backend' => $findings], $tasks);

    expect(array_map(fn ($overlap) => $overlap->findingA->id.'+'.$overlap->findingB->id, $overlaps))->toBe(['BE-001+BE-003', 'BE-002+BE-003'])
        ->and((new OverlapDetector)->detect(['backend' => $findings]))->toBe([]);
});

it('writes frontmatter and title before the body', function () {
    $path = tempDir('writer').'/backend.md';

    (new ReportWriter)->write($path, ['format' => 1, 'audit' => 'backend', 'model' => null], 'Backend Audit — run', "## Summary\nx\n");

    expect(file_get_contents($path))->toBe("---\nformat: 1\naudit: backend\nmodel: \n---\n\n# Backend Audit — run\n\n## Summary\nx\n");
});
