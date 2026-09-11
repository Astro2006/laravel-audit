<?php

use Astro2006\LaravelAudit\Reports\ReportParser;

it('parses sections and findings', function () {
    $report = (new ReportParser)->parse(file_get_contents(fixture('reports/backend-valid.md')));

    expect($report->sectionTitles())->toBe([
        'Summary', 'Findings', 'Conventions baseline', 'Convention scorecard',
        'Dead code candidates', 'Positive observations', 'Limitations',
    ])
        ->and($report->findings)->toHaveCount(2)
        ->and($report->invalidHeadings)->toBe([]);

    [$finding] = $report->findings;

    expect($finding->id)->toBe('BE-001')
        ->and($finding->title)->toBe('Unfiltered request data passed to OrderService::update()')
        ->and($finding->field('severity'))->toBe('high')
        ->and($finding->field('check'))->toBe('mass-assignment')
        ->and($finding->field('occurrences'))->toBe('2')
        ->and($finding->body('Impact'))->toBe('Customers can set `status` on their own orders.');
});

it('ignores structure inside code fences', function () {
    $report = (new ReportParser)->parse(file_get_contents(fixture('reports/backend-valid.md')));

    [$finding] = $report->findings;
    $evidence = $finding->body('Evidence');

    expect($evidence)->toContain('### not a heading')
        ->and($evidence)->toContain('| Severity | critical |')
        ->and($finding->field('severity'))->toBe('high');
});

it('does not treat level-3 headings outside findings as findings', function () {
    $report = (new ReportParser)->parse(file_get_contents(fixture('reports/backend-valid.md')));

    expect($report->section('Limitations'))->toContain('### Außerhalb des Scopes beobachtet');
});

it('records invalid finding headings and the no findings marker', function () {
    $report = (new ReportParser)->parse("## Summary\nx\n\n## Findings\n\n### Something odd\n\nNo findings.\n\n## Limitations\nNone.\n");

    [$heading] = $report->invalidHeadings;

    expect($report->findings)->toBe([])
        ->and($report->invalidHeadings)->toHaveCount(1)
        ->and($heading->line)->toBe(6)
        ->and($report->noFindingsMarker)->toBeTrue();
});

it('keeps pipes inside table values', function () {
    $report = (new ReportParser)->parse("## Findings\n\n### FE-001 — X\n\n| Field | Value |\n|---|---|\n| Locations | `route:GET|HEAD /admin` |\n");

    [$finding] = $report->findings;

    expect($finding->field('locations'))->toBe('`route:GET|HEAD /admin`');
});
