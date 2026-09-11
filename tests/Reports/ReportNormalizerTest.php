<?php

use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Reports\ReportExtractor;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportNormalizer;
use Astro2006\LaravelAudit\Reports\ReportParser;

it('extracts the body from a fenced answer with an introduction and frontmatter', function () {
    $body = (new ReportExtractor)->extract(file_get_contents(fixture('reports/backend-sloppy-answer.md')));

    expect($body)->toStartWith('## Summary')
        ->and($body)->not->toContain('format: 1')
        ->and($body)->not->toContain('```markdown');
});

it('normalizes heading separators, id padding, enum casing and labels', function () {
    $body = (new ReportExtractor)->extract(file_get_contents(fixture('reports/backend-sloppy-answer.md')));
    $normalized = (new ReportNormalizer)->normalize($body);

    expect($normalized)
        ->toContain('### BE-001 — Mass assignment in OrderService')
        ->toContain('| Severity | high |')
        ->toContain('| Confidence | confirmed |')
        ->toContain('| Category | security |')
        ->toContain('| Check | mass-assignment |')
        ->toContain('| Effort | S |')
        ->toContain("**Evidence**\n`\$order->fill(\$data)->save();`")
        ->toContain('**Impact**');
});

it('turns a sloppy but complete answer into a valid report', function () {
    $body = (new ReportNormalizer)->normalize((new ReportExtractor)->extract(file_get_contents(fixture('reports/backend-sloppy-answer.md'))));

    $result = (new ReportLinter(fixture('project')))->lint((new ReportParser)->parse($body), new BackendAudit);

    expect($result->errors)->toBe([])
        ->and($result->findings)->toHaveCount(1);
});

it('leaves code fences untouched', function () {
    $markdown = "```\n| severity | HIGH |\n### BE-1 - x\n```";

    expect((new ReportNormalizer)->normalize($markdown))->toBe($markdown);
});
