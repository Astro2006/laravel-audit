<?php

use Astro2006\LaravelAudit\Reports\FindingEditor;
use Astro2006\LaravelAudit\Reports\ReportParser;

function editorReport(): string
{
    return file_get_contents(fixture('reports/backend-valid.md'));
}

it('removes findings from the body and keeps the rest', function () {
    $body = (new FindingEditor)->remove(editorReport(), ['BE-001']);
    $report = (new ReportParser)->parse($body);

    expect(array_map(fn ($finding) => $finding->id, $report->findings))->toBe(['BE-002'])
        ->and($body)->not->toContain('OrderService::update()')
        ->toContain('## Conventions baseline');
});

it('writes "No findings." once every finding is removed', function () {
    $report = (new ReportParser)->parse((new FindingEditor)->remove(editorReport(), ['BE-001', 'BE-002']));

    expect($report->findings)->toBe([])
        ->and($report->noFindingsMarker)->toBeTrue();
});

it('lowers severity and confidence of downgraded findings only', function () {
    $body = (new FindingEditor)->downgrade(editorReport(), ['BE-001']);
    [$first, $second] = (new ReportParser)->parse($body)->findings;

    expect($first->field('severity'))->toBe('medium')
        ->and($first->field('confidence'))->toBe('suspected')
        ->and($second->field('severity'))->toBe('low')
        ->and($second->field('confidence'))->toBe('likely')
        // The fake table row inside the code fence stays untouched.
        ->and($body)->toContain("| Severity | critical |\n```");
});

it('extracts the blocks of the given findings', function () {
    $blocks = (new FindingEditor)->blocks(editorReport(), ['BE-002']);

    expect($blocks)->toStartWith('### BE-002 — Dead job SyncLegacyInventory')
        ->toEndWith('---')
        ->not->toContain('BE-001');
});
