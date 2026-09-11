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

it('writes frontmatter and title before the body', function () {
    $path = tempDir('writer').'/backend.md';

    (new ReportWriter)->write($path, ['format' => 1, 'audit' => 'backend', 'model' => null], 'Backend Audit — run', "## Summary\nx\n");

    expect(file_get_contents($path))->toBe("---\nformat: 1\naudit: backend\nmodel: \n---\n\n# Backend Audit — run\n\n## Summary\nx\n");
});
